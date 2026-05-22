<?php

namespace App\Services\AI;

use App\Models\Asset;
use App\Models\AssetAlias;
use App\Models\Employee;
use App\Models\MaintenanceReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Session manager untuk klarifikasi AI multi-item via Telegram.
 *
 * Melacak sesi klarifikasi per user/chat.
 * Support SEQUENTIAL: klarifikasi satu per satu per item laporan.
 * Tampilkan progress: "Item 2 dari 4", sisa percobaan per item.
 */
class ClarificationSessionManager
{
    const CACHE_PREFIX = 'clarification:';
    const CACHE_TTL = 1800; // 30 menit
    const MAX_CLARIFICATION_ATTEMPTS = 3;
    const MIN_CONFIDENCE_THRESHOLD = 0.8;

    /**
     * Buat sesi klarifikasi multi-item baru.
     * Dua mode:
     * 1. Dari aiResult (single analyzeWithClarification) + parsedItems
     * 2. Dari ambiguousItems (array hasil filter analyzeReport)
     */
    public static function createSession(
        int $telegramUserId,
        int $chatId,
        array $aiResult,
        ?Employee $employee = null,
        ?string $rawText = null,
        ?array $parsedItems = null,
        ?array $ambiguousItems = null
    ): array {
        $sessionId = self::generateSessionId($telegramUserId);
        $items = [];

        // MODE 1: Dari ambiguousItems langsung (rekomendasi untuk multi-item)
        if (!empty($ambiguousItems)) {
            foreach ($ambiguousItems as $amb) {
                $items[] = [
                    'raw_text' => $amb['action'] ?? $amb['raw_text'] ?? '',
                    'possible_assets' => $amb['possible_assets'] ?? [],
                    'clarification_question' => $amb['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                    'status' => 'pending',
                    'attempts' => 0,
                    'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
                    'resolved_asset_id' => null,
                    'resolved_asset_code' => null,
                    'resolved_asset_description' => null,
                    'resolved_location' => null,
                    'original_action' => $amb['action'] ?? null,
                    'original_status' => $amb['status'] ?? 'done',
                    'notes' => null,
                    'parsed_date' => $amb['parsed_date'] ?? null,
                    'parsed_shift' => $amb['parsed_shift'] ?? null,
                ];
            }
        }
        // MODE 2: Dari analyzeWithClarification (single text)
        else {
            $possibleAssets = $aiResult['possible_assets'] ?? [];
            if (!empty($possibleAssets)) {
                $items[] = [
                    'raw_text' => $aiResult['normalized_text'] ?? $aiResult['summary'] ?? '',
                    'possible_assets' => $possibleAssets,
                    'clarification_question' => $aiResult['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                    'status' => 'pending',
                    'attempts' => 0,
                    'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
                    'resolved_asset_id' => null,
                    'resolved_asset_code' => null,
                    'resolved_asset_description' => null,
                    'resolved_location' => null,
                    'notes' => null,
                    'original_action' => null,
                    'original_status' => 'done',
                    'parsed_date' => null,
                    'parsed_shift' => null,
                ];
            }

            // Dari parsed items — filter yg ambigu
            if ($parsedItems && $aiResult && isset($aiResult['items'])) {
                foreach ($aiResult['items'] as $index => $aiItem) {
                    $originalItem = $parsedItems[$index] ?? null;
                    $conf = $aiItem['confidence'] ?? 0;

                    if ($conf < 0.8 && !empty($aiItem['possible_assets'])) {
                        $items[] = [
                            'raw_text' => $originalItem['action'] ?? $aiItem['original_text'] ?? '',
                            'possible_assets' => $aiItem['possible_assets'] ?? [],
                            'clarification_question' => $aiItem['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                            'status' => 'pending',
                            'attempts' => 0,
                            'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
                            'resolved_asset_id' => null,
                            'resolved_asset_code' => null,
                            'resolved_asset_description' => null,
                            'resolved_location' => null,
                            'notes' => null,
                            'original_action' => $originalItem['action'] ?? null,
                            'original_status' => $originalItem['status'] ?? 'done',
                            'parsed_date' => $aiResult['parsed_date'] ?? null,
                            'parsed_shift' => $aiResult['parsed_shift'] ?? null,
                        ];
                    }
                }
            }
        }

        if (empty($items)) {
            return [];
        }

        $session = [
            'session_id' => $sessionId,
            'telegram_user_id' => $telegramUserId,
            'chat_id' => $chatId,
            'employee_id' => $employee?->id,
            'raw_text' => $rawText ?? $aiResult['raw_text'] ?? '',
            'items' => $items,
            'total_items' => count($items),
            'current_item_index' => 0,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'waiting_user',
            'completed_items' => 0,
        ];

        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return $session;
    }

    /**
     * Ambil item yang sedang aktif (current_item_index)
     */
    public static function getCurrentItem(array $session): ?array
    {
        $idx = $session['current_item_index'] ?? 0;
        return $session['items'][$idx] ?? null;
    }

    /**
     * Buat teks pesan klarifikasi untuk item saat ini
     */
    public static function buildClarificationMessage(array $session, array $currentItem): string
    {
        $idx = $session['current_item_index'] ?? 0;
        $total = $session['total_items'] ?? 1;
        $attemptsLeft = $currentItem['max_attempts'] - $currentItem['attempts'];

        $msg = "🤖 <b>Analisa AI - Item " . ($idx + 1) . " dari {$total}</b>\n\n";
        $msg .= "Laporan: <i>" . e($currentItem['raw_text']) . "</i>\n\n";

        $possibleAssets = $currentItem['possible_assets'] ?? [];

        if (!empty($possibleAssets)) {
            $msg .= "Saya menemukan beberapa kemungkinan. Pilih yang sesuai:\n\n";

            foreach ($possibleAssets as $i => $asset) {
                $num = $i + 1;
                $pct = round(($asset['confidence'] ?? 0) * 100);
                $loc = $asset['location'] ?? '';
                $desc = $asset['description'] ?? '';
                $code = $asset['tech_ident_no'] ?? '';
                $msg .= "<b>{$num}.</b> {$code} - {$desc}";
                if ($loc) $msg .= " ({$loc})";
                $msg .= " [{$pct}%]\n";
            }

            $msg .= "\n<b>Cara memilih:</b>\n";
            $msg .= "- Ketik angka (1, 2, 3...)\n";
            $msg .= "- Atau ketik kode equipment\n";
            $msg .= "- Atau ketik <b>lewati</b> jika tidak cocok\n";
        } else {
            $msg .= $currentItem['clarification_question'] . "\n\n";
            $msg .= "Silakan kirim detail tambahan, atau ketik <b>lewati</b>.\n";
        }

        $msg .= "\nSisa percobaan: {$attemptsLeft}x";

        return $msg;
    }

    /**
     * Buat tombol inline keyboard untuk item saat ini
     */
    public static function buildClarificationKeyboard(array $session, array $currentItem): array
    {
        $buttons = [];
        $possibleAssets = $currentItem['possible_assets'] ?? [];

        foreach ($possibleAssets as $i => $asset) {
            $num = $i + 1;
            $label = mb_substr($asset['description'] ?? '', 0, 35);
            $buttons[] = [
                'text' => "{$num} {$label}",
                'callback_data' => "clarify_item:{$session['session_id']}:{$num}",
            ];
        }

        // Tombol lewati
        $buttons[] = [
            'text' => 'Lewati item ini',
            'callback_data' => "clarify_skip:{$session['session_id']}:",
        ];

        return $buttons;
    }

    /**
     * Proses balasan user untuk item saat ini
     */
    public static function processCurrentItem(string $sessionId, string $userMessage): array
    {
        $session = self::getSession($sessionId);
        if (!$session) {
            return ['success' => false, 'error' => 'Sesi tidak ditemukan atau sudah kadaluarsa.'];
        }

        $idx = $session['current_item_index'] ?? 0;
        $total = $session['total_items'] ?? 1;

        if ($idx >= $total) {
            return ['success' => false, 'error' => 'Semua item sudah selesai.'];
        }

        $msg = trim(strtolower($userMessage));
        $currentItem = &$session['items'][$idx];

        // Cek apakah user ingin lewati
        if (in_array($msg, ['lewati', 'skip', 'none', 'tidak ada', '0'])) {
            $currentItem['status'] = 'skipped';
            $currentItem['notes'] = 'User memilih lewati';
            $session['completed_items']++;

            $nextIdx = $idx + 1;
            if ($nextIdx >= $total) {
                $session['status'] = 'completed';
                Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);
                return [
                    'success' => true,
                    'resolved' => false,
                    'skipped' => true,
                    'completed' => true,
                    'item_index' => $idx,
                    'session' => $session,
                ];
            }

            $session['current_item_index'] = $nextIdx;
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'skipped' => true,
                'completed' => false,
                'item_index' => $idx,
                'next_item_index' => $nextIdx,
                'total_items' => $total,
                'remaining_items' => $total - $idx - 1,
                'session' => $session,
            ];
        }

        // Increment attempts untuk item ini
        $currentItem['attempts']++;
        $session['updated_at'] = now()->toIso8601String();

        // Coba cocokkan
        $selectedAsset = self::matchUserSelection($userMessage, $currentItem['possible_assets']);

        if ($selectedAsset) {
            // Resolved untuk item ini
            $currentItem['status'] = 'resolved';
            $currentItem['resolved_asset_id'] = $selectedAsset['id'];
            $currentItem['resolved_asset_code'] = $selectedAsset['tech_ident_no'];
            $currentItem['resolved_asset_description'] = $selectedAsset['description'] ?? '';
            $currentItem['resolved_location'] = $selectedAsset['location'] ?? '';
            $session['completed_items']++;

            self::learnAlias($currentItem['raw_text'], $selectedAsset, $session['employee_id']);

            $nextIdx = $idx + 1;
            if ($nextIdx >= $total) {
                $session['status'] = 'completed';
                Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);
                return [
                    'success' => true,
                    'resolved' => true,
                    'asset' => $selectedAsset,
                    'completed' => true,
                    'item_index' => $idx,
                    'total_items' => $total,
                    'session' => $session,
                ];
            }

            $session['current_item_index'] = $nextIdx;
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'resolved' => true,
                'asset' => $selectedAsset,
                'completed' => false,
                'item_index' => $idx,
                'next_item_index' => $nextIdx,
                'total_items' => $total,
                'remaining_items' => $total - $idx - 1,
                'session' => $session,
            ];
        }

        // Gagal cocok - cek max attempts
        if ($currentItem['attempts'] >= $currentItem['max_attempts']) {
            $currentItem['status'] = 'unidentified';
            $currentItem['notes'] = 'Max attempts exceeded';
            $session['completed_items']++;

            $nextIdx = $idx + 1;
            if ($nextIdx >= $total) {
                $session['status'] = 'completed';
                Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);
                return [
                    'success' => true,
                    'resolved' => false,
                    'unidentified' => true,
                    'completed' => true,
                    'item_index' => $idx,
                    'total_items' => $total,
                    'session' => $session,
                ];
            }

            $session['current_item_index'] = $nextIdx;
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'resolved' => false,
                'unidentified' => true,
                'completed' => false,
                'item_index' => $idx,
                'next_item_index' => $nextIdx,
                'total_items' => $total,
                'remaining_items' => $total - $idx - 1,
                'session' => $session,
            ];
        }

        // Masih ada sisa
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'resolved' => false,
            'attempts' => $currentItem['attempts'],
            'max_attempts' => $currentItem['max_attempts'],
            'remaining_attempts' => $currentItem['max_attempts'] - $currentItem['attempts'],
            'item_index' => $idx,
            'session' => $session,
            'error' => 'Pilihan tidak dikenali. Silakan ketik angka, kode equipment, atau "lewati".',
        ];
    }

    /**
     * Ambil ringkasan hasil akhir dari semua item
     */
    public static function getSummary(array $session): array
    {
        $results = [];
        $resolved = 0;
        $unidentified = 0;
        $skipped = 0;

        foreach ($session['items'] as $i => $item) {
            $status = $item['status'] ?? 'pending';
            $entry = [
                'index' => $i + 1,
                'raw_text' => $item['raw_text'] ?? '',
                'status' => $status,
            ];

            if ($status === 'resolved') {
                $entry['asset_code'] = $item['resolved_asset_code'] ?? '';
                $entry['asset_description'] = $item['resolved_asset_description'] ?? '';
                $entry['location'] = $item['resolved_location'] ?? '';
                $resolved++;
            } elseif ($status === 'unidentified') {
                $unidentified++;
                $entry['asset_code'] = 'UNIDENTIFIED';
            } elseif ($status === 'skipped') {
                $skipped++;
                $entry['asset_code'] = 'SKIPPED';
            }

            $results[] = $entry;
        }

        return [
            'items' => $results,
            'total' => count($results),
            'resolved' => $resolved,
            'unidentified' => $unidentified,
            'skipped' => $skipped,
        ];
    }

    /**
     * Simpan semua item yang sudah selesai ke database
     */
    public static function saveAllReports(array $session): array
    {
        $savedReportIds = [];
        $employeeId = $session['employee_id'];

        foreach ($session['items'] as $item) {
            try {
                $reportData = [
                    'employee_id' => $employeeId,
                    'report_date' => now(),
                    'shift' => 'pagi',
                    'is_from_telegram' => true,
                    'clarification_session_id' => $session['session_id'],
                    'original_raw_text' => $session['raw_text'] ?? '',
                    'action_taken' => $item['raw_text'] ?? '',
                    'notes' => $item['notes'] ?? null,
                ];

                if (!empty($item['parsed_date'])) {
                    $reportData['report_date'] = $item['parsed_date'];
                }
                if (!empty($item['parsed_shift'])) {
                    $reportData['shift'] = $item['parsed_shift'];
                }

                if ($item['status'] === 'resolved') {
                    $reportData['asset_id'] = $item['resolved_asset_id'];
                    $reportData['asset_code'] = $item['resolved_asset_code'] ?? '';
                    $reportData['status'] = $item['original_status'] ?? 'done';
                    $reportData['clarification_status'] = 'resolved';
                } elseif ($item['status'] === 'unidentified') {
                    $reportData['asset_id'] = null;
                    $reportData['asset_code'] = 'UNIDENTIFIED';
                    $reportData['status'] = 'pending';
                    $reportData['clarification_status'] = 'unidentified';
                    $reportData['notes'] = ($reportData['notes'] ?? '') .
                        ' | Laporan tidak teridentifikasi setelah klarifikasi. Perlu review admin.';
                } else {
                    $reportData['asset_id'] = null;
                    $reportData['asset_code'] = 'UNIDENTIFIED';
                    $reportData['status'] = 'pending';
                    $reportData['clarification_status'] = 'unidentified';
                    $reportData['notes'] = ($reportData['notes'] ?? '') .
                        ' | User melewati klarifikasi. Perlu review admin.';
                }

                $report = MaintenanceReport::create($reportData);
                $savedReportIds[] = $report?->id;
            } catch (\Throwable $e) {
                Log::error("Gagal simpan report dari klarifikasi: {$e->getMessage()}");
            }
        }

        return $savedReportIds;
    }

    /**
     * Buat teks ringkasan akhir untuk dikirim ke user
     */
    public static function buildSummaryMessage(array $summary, ?Employee $employee = null): string
    {
        $msg = "=== Semua laporan selesai diproses! ===\n\n";
        if ($employee) {
            $msg .= "Teknisi: {$employee->name}\n";
        }
        $msg .= "Total item: {$summary['total']}\n";
        $msg .= "Terkonfirmasi: {$summary['resolved']}\n";
        $msg .= "Tidak dikenal: {$summary['unidentified']}\n";
        $msg .= "Dilewati: {$summary['skipped']}\n\n";
        $msg .= "----------------------------------------\n";

        foreach ($summary['items'] as $item) {
            $icon = match($item['status']) {
                'resolved' => '(v)',
                'unidentified' => '(x)',
                'skipped' => '(-)',
                default => '(?)',
            };

            if ($item['status'] === 'resolved') {
                $msg .= "{$icon} Item {$item['index']}: {$item['asset_code']} - {$item['asset_description']}\n";
                if ($item['location']) {
                    $msg .= "   Lokasi: {$item['location']}\n";
                }
            } elseif ($item['status'] === 'unidentified') {
                $msg .= "{$icon} Item {$item['index']}: Equipment tidak dikenal\n";
            } else {
                $msg .= "{$icon} Item {$item['index']}: Dilewati user\n";
            }
        }

        $unidentified = $summary['unidentified'] + $summary['skipped'];
        if ($unidentified > 0) {
            $msg .= "\n{$unidentified} laporan perlu review admin.\n";
            $msg .= "Silakan cek di panel web.\n";
        }

        $msg .= "\nAI belajar: Laporan yang sudah dikonfirmasi akan langsung dikenali lain kali.";

        return $msg;
    }

    /**
     * Ambil sesi berdasarkan session ID
     */
    public static function getSession(string $sessionId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $sessionId);
    }

    /**
     * Ambil sesi aktif berdasarkan Telegram User ID
     */
    public static function getActiveSession(int $telegramUserId): ?array
    {
        $sessionId = self::generateSessionId($telegramUserId);
        $session = Cache::get(self::CACHE_PREFIX . $sessionId);

        if ($session && $session['status'] === 'waiting_user') {
            return $session;
        }

        return null;
    }

    /**
     * Hapus sesi
     */
    public static function destroySession(string $sessionId): void
    {
        Cache::forget(self::CACHE_PREFIX . $sessionId);
    }

    /**
     * Cocokkan pesan user dengan opsi yang ditawarkan
     */
    protected static function matchUserSelection(string $userMessage, array $possibleAssets): ?array
    {
        $msg = trim($userMessage);

        if (is_numeric($msg)) {
            $index = (int)$msg - 1;
            if (isset($possibleAssets[$index])) {
                return $possibleAssets[$index];
            }
        }

        if (preg_match('/^[a-zA-Z]$/', $msg)) {
            $index = ord(strtoupper($msg)) - ord('A');
            if (isset($possibleAssets[$index])) {
                return $possibleAssets[$index];
            }
        }

        foreach ($possibleAssets as $asset) {
            if (strcasecmp($msg, $asset['tech_ident_no'] ?? '') === 0) {
                return $asset;
            }
            if (is_numeric($msg) && (int)$msg === ($asset['id'] ?? 0)) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Generate session ID dari Telegram User ID
     */
    protected static function generateSessionId(int $telegramUserId): string
    {
        return 'user_' . $telegramUserId;
    }

    /**
     * Simpan alias baru dari hasil klarifikasi (learning loop)
     */
    protected static function learnAlias(string $originalText, array $selectedAsset, ?int $employeeId): void
    {
        try {
            if (empty($originalText) || empty($selectedAsset['id'])) return;

            $alias = AssetAlias::firstOrCreate(
                [
                    'alias' => strtolower(trim($originalText)),
                    'asset_id' => $selectedAsset['id'],
                ],
                [
                    'employee_id' => $employeeId,
                    'confidence_score' => 85,
                    'auto_generated' => true,
                    'source' => 'clarification',
                ]
            );

            if (!$alias->wasRecentlyCreated) {
                $alias->increment('usage_count');
                $alias->increment('confidence_score', 2);
                if ($alias->confidence_score > 100) {
                    $alias->update(['confidence_score' => 100]);
                }
            }

            Log::info("AI belajar alias baru dari klarifikasi: '{$originalText}' -> {$selectedAsset['tech_ident_no']}");
        } catch (\Throwable $e) {
            Log::warning("Gagal simpan alias dari klarifikasi: {$e->getMessage()}");
        }
    }
}
