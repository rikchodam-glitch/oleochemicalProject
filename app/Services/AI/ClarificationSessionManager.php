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
    const AUTO_RESOLVE_THRESHOLD = 0.95;
    const ASK_DURATION_MESSAGE = 'Berapa durasi pengerjaan? (contoh: 2 jam, 1.5, 30 menit, atau 0 untuk lewati)';

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
                $conf = $amb['confidence'] ?? 0;

                // AUTO-RESOLVE jika confidence >= 95%
                if ($conf >= self::AUTO_RESOLVE_THRESHOLD && !empty($amb['suggested_asset_id'])) {
                    $asset = \App\Models\Asset::find($amb['suggested_asset_id']);
                    if ($asset) {
                        $items[] = [
                            'raw_text' => $amb['action'] ?? $amb['raw_text'] ?? '',
                            'possible_assets' => [],
                            'confidence' => $conf,
                            'status' => 'resolved',
                            'work_type' => 'equipment',
                            'attempts' => 0,
                            'max_attempts' => 0,
                            'resolved_asset_id' => $asset->id,
                            'resolved_asset_code' => $asset->tech_ident_no ?? '',
                            'resolved_asset_description' => $asset->description ?? '',
                            'resolved_location' => ($asset->area->name ?? '') . ($asset->subArea->name ? ' - ' . $asset->subArea->name : ''),
                            'original_action' => $amb['action'] ?? null,
                            'original_status' => $amb['status'] ?? 'done',
                            'notes' => 'Auto-resolved (confidence ' . round($conf * 100) . '%)',
                            'parsed_date' => $amb['parsed_date'] ?? null,
                            'parsed_shift' => $amb['parsed_shift'] ?? null,
                        ];
                        continue;
                    }
                }

                $items[] = [
                    'raw_text' => $amb['action'] ?? $amb['raw_text'] ?? '',
                    'possible_assets' => $amb['possible_assets'] ?? [],
                    'confidence' => $conf,
                    'clarification_question' => $amb['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                    'status' => 'pending',
                    'work_type' => null,
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
                    'confidence' => 0,
                    'clarification_question' => $aiResult['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                    'status' => 'pending',
                    'work_type' => null,
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
                    $suggestedAssetId = $aiItem['suggested_asset_id'] ?? null;

                    // AUTO-RESOLVE jika confidence >= 95%
                    if ($conf >= self::AUTO_RESOLVE_THRESHOLD && !empty($suggestedAssetId)) {
                        $asset = \App\Models\Asset::find($suggestedAssetId);
                        if ($asset) {
                            $items[] = [
                                'raw_text' => $originalItem['action'] ?? $aiItem['original_text'] ?? '',
                                'possible_assets' => [],
                                'confidence' => $conf,
                                'status' => 'resolved',
                                'work_type' => 'equipment',
                                'attempts' => 0,
                                'max_attempts' => 0,
                                'resolved_asset_id' => $asset->id,
                                'resolved_asset_code' => $asset->tech_ident_no ?? '',
                                'resolved_asset_description' => $asset->description ?? '',
                                'resolved_location' => ($asset->area->name ?? '') . ($asset->subArea->name ? ' - ' . $asset->subArea->name : ''),
                                'notes' => 'Auto-resolved (confidence ' . round($conf * 100) . '%)',
                                'original_action' => $originalItem['action'] ?? null,
                                'original_status' => $originalItem['status'] ?? 'done',
                                'parsed_date' => $aiResult['parsed_date'] ?? null,
                                'parsed_shift' => $aiResult['parsed_shift'] ?? null,
                            ];
                            continue;
                        }
                    }

                    // Item ambigu (confidence < 0.8) tapi ada possible_assets
                    if ($conf < self::MIN_CONFIDENCE_THRESHOLD && !empty($aiItem['possible_assets'])) {
                        $items[] = [
                            'raw_text' => $originalItem['action'] ?? $aiItem['original_text'] ?? '',
                            'possible_assets' => $aiItem['possible_assets'] ?? [],
                            'confidence' => $conf,
                            'clarification_question' => $aiItem['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
                            'status' => 'pending',
                            'work_type' => null,
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
     * 3 kemungkinan state:
     * 1. Ada opsi (possible_assets tidak kosong) → tampilkan A, B, C + opsi "Area" di akhir + input manual
     * 2. Tidak ada opsi (possible_assets kosong) → tanya "Area atau Equipment Baru?" + input manual
     * 3. Auto-resolve (confidence >= 95%) → tidak dipanggil, sudah langsung resolved
     */
    public static function buildClarificationMessage(array $session, array $currentItem): string
    {
        $idx = $session['current_item_index'] ?? 0;
        $total = $session['total_items'] ?? 1;
        $attemptsLeft = $currentItem['max_attempts'] - $currentItem['attempts'];

        $msg = "\xF0\x9F\x93\x8B <b>Perbaikan Laporan: " . ($idx + 1) . "/{$total}</b>\n\n";
        $msg .= "Laporan: <i>" . e($currentItem['raw_text']) . "</i>\n\n";

        $possibleAssets = $currentItem['possible_assets'] ?? [];
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        if (!empty($possibleAssets)) {
            $msg .= "Pilih equipment yang dimaksud:\n\n";

            foreach ($possibleAssets as $i => $asset) {
                $letter = $letters[$i] ?? '?';
                $pct = round(($asset['confidence'] ?? 0) * 100);
                $loc = $asset['location'] ?? '';
                $desc = $asset['description'] ?? '';
                $code = $asset['tech_ident_no'] ?? '';
                $msg .= "<b>{$letter}.</b> {$code} - {$desc}";
                if ($loc) $msg .= " ({$loc})";
                $msg .= " [{$pct}%]\n";
            }

            // Opsi tambahan: Area, Lewati, dan Manual
            $nextLetter = $letters[count($possibleAssets)] ?? 'Z';
            $msg .= "\n<b>{$nextLetter}.</b> Pekerjaan Area (bukan equipment)\n";
            $msg .= "<b>0.</b> Lewati item ini\n";
            $msg .= "<b>#.</b> Tulis manual (ketik <code>#nama equipment/area</code>)\n\n";

            $msg .= "<b>Cara:</b> Ketik huruf (A, B, C...), <b>Area</b>, <b>0</b>, atau <b>#teks manual</b>";
        } else {
            // Tidak ada opsi sama sekali — tanya Area, Equipment Baru, atau Manual
            $msg .= "Saya tidak menemukan equipment yang cocok.\n\n";
            $msg .= "<b>A.</b> Pekerjaan Area (lokasi/bangunan)\n";
            $msg .= "<b>B.</b> Equipment Baru (perlu didaftarkan admin)\n";
            $msg .= "<b>0.</b> Lewati item ini\n";
            $msg .= "<b>#.</b> Tulis manual (ketik <code>#nama area/equipment</code>)\n\n";
            $msg .= "<b>Cara:</b> Ketik <b>A</b>, <b>B</b>, <b>0</b>, atau <b>#teks manual</b>";
        }

        if ($attemptsLeft > 0 && $attemptsLeft < $currentItem['max_attempts']) {
            $msg .= "\n\nSisa percobaan: {$attemptsLeft}x";
        }

        return $msg;
    }

    /**
     * Buat tombol inline keyboard untuk item saat ini
     * Include tombol Area jika opsi terbatas
     */
    public static function buildClarificationKeyboard(array $session, array $currentItem): array
    {
        $buttons = [];
        $possibleAssets = $currentItem['possible_assets'] ?? [];
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($possibleAssets as $i => $asset) {
            $letter = $letters[$i] ?? '?';
            $label = mb_substr($asset['description'] ?? '', 0, 30);
            $buttons[] = [
                'text' => "{$letter} {$label}",
                'callback_data' => "clarify_item:{$session['session_id']}:{$letter}",
            ];
        }

        // Tombol Pekerjaan Area
        $nextLetter = $letters[count($possibleAssets)] ?? 'Z';
        $buttons[] = [
            'text' => "{$nextLetter} Pekerjaan Area",
            'callback_data' => "clarify_area:{$session['session_id']}:",
        ];

        // Tombol Equipment Baru (jika tidak ada opsi)
        if (empty($possibleAssets)) {
            $buttons[] = [
                'text' => 'B Equipment Baru',
                'callback_data' => "clarify_new:{$session['session_id']}:",
            ];
        }

        // Tombol lewati
        $buttons[] = [
            'text' => '0 Lewati',
            'callback_data' => "clarify_skip:{$session['session_id']}:",
        ];

        return $buttons;
    }

    /**
     * Proses balasan user untuk item saat ini — SEQUENTIAL
     *
     * Flow per item:
     * 1. User diminta pilih equipment (A/B/C/Area/Baru/Lewati)
     * 2. Setelah terpilih, cek apakah durasi sudah ada di parsed text
     * 3. Jika belum, tanya durasi (2 jam, 1.5h, 30 menit)
     * 4. Setelah durasi diisi, lanjut ke item berikutnya
     *
     * 4 jenis outcome:
     * - Resolved to Asset — user memilih equipment
     * - Area — pekerjaan area
     * - Equipment Baru — perlu didaftarkan admin
     * - Lewati — user skip
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

        $msg = trim($userMessage);
        $msgLower = trim(strtolower($userMessage));
        $currentItem = &$session['items'][$idx];

        // ========== CEK: JAWABAN DURASI ==========
        // Jika sesi sedang dalam mode tanya durasi untuk item ini
        if (($currentItem['awaiting_duration'] ?? false)) {
            $parsedDuration = self::parseDurationInput($msgLower);
            if ($parsedDuration !== null) {
                $currentItem['duration_hours'] = $parsedDuration;
                $currentItem['awaiting_duration'] = false;
                $currentItem['notes'] = ($currentItem['notes'] ?? '') . ' | Durasi: ' . $parsedDuration . ' jam';
                $session['completed_items']++;

                return self::moveToNextItem($session, $sessionId, $idx, $total);
            }

            // Input durasi tidak valid — tanya lagi
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);
            return [
                'success' => false,
                'resolved' => false,
                'awaiting_duration' => true,
                'item_index' => $idx,
                'session' => $session,
                'error' => 'Format durasi tidak dikenali. Contoh: 2 (jam), 1.5, 30 (menit), atau 0 untuk lewati.',
            ];
        }

        // ========== CEK: LEWATI ==========
        if (in_array($msgLower, ['lewati', 'skip', 'none', 'tidak ada', '0', '0.'])) {
            $currentItem['status'] = 'skipped';
            $currentItem['work_type'] = 'skipped';
            $currentItem['notes'] = 'User memilih lewati';
            $session['completed_items']++;

            return self::moveToNextItem($session, $sessionId, $idx, $total);
        }

        // ========== CEK: PEKERJAAN AREA ==========
        if (in_array($msgLower, ['area', 'a', 'lokasi', 'pekerjaan area'])) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'area';
            $currentItem['notes'] = 'User mengkonfirmasi ini pekerjaan area';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'AREA';
            $currentItem['resolved_asset_description'] = 'Pekerjaan Area';
            $session['completed_items']++;

            return self::afterResolvedCheckDuration($session, $sessionId, $idx, $total);
        }

        // ========== CEK: EQUIPMENT BARU ==========
        if (in_array($msgLower, ['b', 'baru', 'equipment baru', 'new'])) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'new_equipment';
            $currentItem['notes'] = 'User mengkonfirmasi ini equipment baru, perlu didaftarkan admin';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'NEW';
            $currentItem['resolved_asset_description'] = 'Equipment Baru';
            $session['completed_items']++;

            return self::afterResolvedCheckDuration($session, $sessionId, $idx, $total);
        }

        // ========== CEK: INPUT MANUAL (diawali #) ==========
        if (str_starts_with($msg, '#')) {
            $manualText = trim(ltrim($msg, '#'));
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'manual';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'MANUAL';
            $currentItem['resolved_asset_description'] = $manualText;
            $currentItem['notes'] = 'User input manual: ' . $manualText;
            $session['completed_items']++;

            return self::afterResolvedCheckDuration($session, $sessionId, $idx, $total);
        }

        // ========== CEK: INPUT MANUAL (teks bebas > 2 kata yang bukan perintah) ==========
        $isTeksBebas = !in_array($msgLower, ['area', 'lokasi', 'pekerjaan area', 'baru', 'new', 'equipment baru'])
            && !preg_match('/^[a-h0-9]$/i', $msg)
            && str_word_count($msg) >= 2;

        if ($isTeksBebas) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'manual';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'MANUAL';
            $currentItem['resolved_asset_description'] = $msg;
            $currentItem['notes'] = 'User input manual: ' . $msg;
            $session['completed_items']++;

            return self::afterResolvedCheckDuration($session, $sessionId, $idx, $total);
        }

        // ========== CEK: PILIH DARI OPSI ==========
        $selectedAsset = self::matchUserSelection($userMessage, $currentItem['possible_assets'] ?? []);

        if ($selectedAsset) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'equipment';
            $currentItem['resolved_asset_id'] = $selectedAsset['id'];
            $currentItem['resolved_asset_code'] = $selectedAsset['tech_ident_no'] ?? '';
            $currentItem['resolved_asset_description'] = $selectedAsset['description'] ?? '';
            $currentItem['resolved_location'] = $selectedAsset['location'] ?? '';
            $currentItem['notes'] = 'Dipilih user dari opsi';
            $session['completed_items']++;

            self::learnAlias($currentItem['raw_text'], $selectedAsset, $session['employee_id']);

            return self::afterResolvedCheckDuration($session, $sessionId, $idx, $total);
        }

        // ========== GAGAL COCOK ==========
        $currentItem['attempts']++;
        $session['updated_at'] = now()->toIso8601String();

        // Cek max attempts
        if ($currentItem['attempts'] >= $currentItem['max_attempts']) {
            $currentItem['status'] = 'unidentified';
            $currentItem['work_type'] = 'unknown';
            $currentItem['notes'] = 'Max attempts exceeded';
            $session['completed_items']++;

            return self::moveToNextItem($session, $sessionId, $idx, $total);
        }

        // Masih ada sisa percobaan
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'resolved' => false,
            'work_type' => null,
            'attempts' => $currentItem['attempts'],
            'max_attempts' => $currentItem['max_attempts'],
            'remaining_attempts' => $currentItem['max_attempts'] - $currentItem['attempts'],
            'item_index' => $idx,
            'session' => $session,
            'error' => 'Pilihan tidak dikenali. Ketik huruf (A, B...), Area, Baru, atau 0 untuk lewati.',
        ];
    }

    /**
     * Setelah item resolved, cek apakah durasi sudah ada.
     * Jika belum, set mode 'awaiting_duration' dan tanya user.
     */
    protected static function afterResolvedCheckDuration(array &$session, string $sessionId, int $currentIdx, int $total): array
    {
        $currentItem = &$session['items'][$currentIdx];

        // Cek apakah durasi sudah tercantum di parsed text
        $hasDuration = !empty($currentItem['duration_hours']);

        if (!$hasDuration) {
            // Set mode tanya durasi
            $currentItem['awaiting_duration'] = true;
            $session['status'] = 'waiting_duration';
            $session['updated_at'] = now()->toIso8601String();
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'resolved' => true,
                'awaiting_duration' => true,
                'item_index' => $currentIdx,
                'total_items' => $total,
                'session' => $session,
                'duration_question' => self::ASK_DURATION_MESSAGE,
            ];
        }

        // Durasi sudah ada — lanjut ke item berikutnya
        return self::moveToNextItem($session, $sessionId, $currentIdx, $total);
    }

    /**
     * Parse input durasi dari user.
     * Format yang didukung:
     *   "2" / "2 jam" / "2h" -> 2.0
     *   "1.5" / "1,5" / "1.5 jam" / "1.5h" -> 1.5
     *   "30 menit" / "30m" / "0.5" -> 0.5
     *   "0" / "0 jam" / "tidak tahu" -> null (skip/isikan default)
     */
    public static function parseDurationInput(string $input): ?float
    {
        // Bersihkan
        $input = trim($input);

        // Skip
        if (in_array($input, ['0', '0 jam', '0h', 'tidak tahu', '-'])) {
            return 0;
        }

        // Cari pola "X jam" atau "X.Y jam"
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:jam|h)\s*$/i', $input, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        // Cari pola "X menit" atau "Xm"
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:menit|m)\s*$/i', $input, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return round($val / 60, 1);
        }

        // Cari angka biasa (anggap jam)
        if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $input, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            // Jika > 8, mungkin ini menit, konversi
            if ($val > 8) {
                return round($val / 60, 1);
            }
            return $val;
        }

        return null;
    }

    /**
     * Pindah ke item berikutnya atau selesaikan sesi
     */
    protected static function moveToNextItem(array &$session, string $sessionId, int $currentIdx, int $total): array
    {
        $nextIdx = $currentIdx + 1;

        if ($nextIdx >= $total) {
            // Semua item selesai
            $session['status'] = 'completed';
            $session['current_item_index'] = $total; // pastikan di luar range
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'resolved' => true,
                'completed' => true,
                'item_index' => $currentIdx,
                'total_items' => $total,
                'session' => $session,
            ];
        }

        $session['current_item_index'] = $nextIdx;
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'resolved' => true,
            'completed' => false,
            'item_index' => $currentIdx,
            'next_item_index' => $nextIdx,
            'total_items' => $total,
            'remaining_items' => $total - $nextIdx,
            'session' => $session,
        ];
    }

    /**
     * Ambil ringkasan hasil akhir dari semua item
     */
    public static function getSummary(array $session): array
    {
        $results = [];
        $resolved = 0;
        $area = 0;
        $newEquipment = 0;
        $unidentified = 0;
        $skipped = 0;

        foreach ($session['items'] as $i => $item) {
            $status = $item['status'] ?? 'pending';
            $workType = $item['work_type'] ?? 'unknown';
            $entry = [
                'index' => $i + 1,
                'raw_text' => $item['raw_text'] ?? '',
                'status' => $status,
                'work_type' => $workType,
            ];

            switch ($workType) {
                case 'equipment':
                    $entry['asset_code'] = $item['resolved_asset_code'] ?? '';
                    $entry['asset_description'] = $item['resolved_asset_description'] ?? '';
                    $entry['location'] = $item['resolved_location'] ?? '';
                    $resolved++;
                    break;
                case 'area':
                    $entry['asset_code'] = 'AREA';
                    $entry['asset_description'] = 'Pekerjaan Area';
                    $area++;
                    break;
                case 'new_equipment':
                    $entry['asset_code'] = 'NEW';
                    $entry['asset_description'] = 'Equipment Baru';
                    $newEquipment++;
                    break;
                case 'skipped':
                    $entry['asset_code'] = 'SKIPPED';
                    $skipped++;
                    break;
                case 'manual':
                    $entry['asset_code'] = 'MANUAL';
                    $entry['asset_description'] = $item['resolved_asset_description'] ?? 'Input manual';
                    $newEquipment++; // Anggap perlu review
                    break;
                default:
                    $entry['asset_code'] = 'UNIDENTIFIED';
                    $unidentified++;
                    break;
            }

            $results[] = $entry;
        }

        return [
            'items' => $results,
            'total' => count($results),
            'resolved' => $resolved,
            'area' => $area,
            'new_equipment' => $newEquipment,
            'unidentified' => $unidentified,
            'skipped' => $skipped,
        ];
    }

    /**
     * Simpan semua item yang sudah selesai ke database
     * Handle 3 work_type: equipment, area, new_equipment, skipped, unknown
     * Return: array of ['id' => numeric_id, 'telegram_report_id' => 'LMS-...', 'action' => string, 'asset_code' => string]
     */
    public static function saveAllReports(array $session): array
    {
        $savedReports = [];
        $employeeId = $session['employee_id'];

                foreach ($session['items'] as $item) {
            try {
                $dateStr = now()->format('Ymd');

                // Cari ID terakhir di DB dengan format LMS-YYYYMMDD-XXX
                $lastReport = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
                    ->orderBy('telegram_report_id', 'desc')
                    ->first(['telegram_report_id']);

                $nextSeq = 1;
                if ($lastReport && $lastReport->telegram_report_id) {
                    $lastId = $lastReport->telegram_report_id;
                    if (preg_match('/-(\d{3})$/', $lastId, $m)) {
                        $nextSeq = (int)$m[1] + 1;
                    }
                }

                // Pastikan sequence tidak melebihi 999
                if ($nextSeq > 999) {
                    $nextSeq = 1;
                }

                $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

                $workType = $item['work_type'] ?? 'unknown';

                $status = $item['status'] ?? 'pending';
                $needsAdminReview = false;
                $assetId = null;
                $aiSuggested = false;

                switch ($workType) {
                    case 'equipment':
                        // Resolved ke equipment
                        $assetId = $item['resolved_asset_id'];
                        $status = $item['original_status'] ?? 'done';
                        $aiSuggested = true;
                        $needsAdminReview = false;
                        break;

                    case 'area':
                        // Pekerjaan area — valid, tidak perlu asset
                        $assetId = null;
                        $status = 'done';
                        $aiSuggested = false;
                        $needsAdminReview = false;
                        break;

                    case 'new_equipment':
                        // Equipment baru — perlu didaftarkan admin
                        $assetId = null;
                        $status = 'pending';
                        $aiSuggested = false;
                        $needsAdminReview = true;
                        break;

                    case 'skipped':
                        // Dilewati user
                        $assetId = null;
                        $status = 'pending';
                        $needsAdminReview = true;
                        break;

                    case 'manual':
                        // User input manual — simpan teks asli, perlu review admin
                        $assetId = null;
                        $status = 'done';
                        $aiSuggested = false;
                        $needsAdminReview = true;
                        break;

                    default:
                        // Unknown / unidentified
                        $assetId = null;
                        $status = 'pending';
                        $needsAdminReview = true;
                        break;
                }

                $durationHours = $item['duration_hours'] ?? null;
                // Jika durasi masih null setelah clarifikasi, default 0
                if ($durationHours === null) {
                    $durationHours = 0;
                }

                $reportData = [
                    'employee_id' => (int)$employeeId,
                    'asset_id' => $assetId ? (int)$assetId : null,
                    'report_date' => is_string($item['parsed_date'] ?? null) ? $item['parsed_date'] : now()->format('Y-m-d'),
                    'shift' => (string)($item['parsed_shift'] ?? '1'),
                    'source' => 'telegram',
                    'telegram_report_id' => (string)$telegramReportId,
                    'raw_text' => (string)($session['raw_text'] ?? ''),
                    'action_taken' => (string)($item['raw_text'] ?? ''),
                    'status' => (string)$status,
                    'ai_suggested' => (bool)$aiSuggested,
                    'needs_admin_review' => (bool)$needsAdminReview,
                ];

                $report = MaintenanceReport::create($reportData);
                if ($report) {
                    $assetCode = match($workType) {
                        'equipment' => $item['resolved_asset_code'] ?? '',
                        'area' => 'AREA',
                        'new_equipment' => 'NEW',
                        'manual' => 'MANUAL: ' . ($item['resolved_asset_description'] ?? ''),
                        'skipped' => 'SKIPPED',
                        default => 'UNIDENTIFIED',
                    };
                    $savedReports[] = [
                        'id' => $report->id,
                        'telegram_report_id' => $telegramReportId,
                        'action' => $item['raw_text'] ?? '',
                        'asset_code' => $assetCode,
                        'asset_description' => $item['resolved_asset_description'] ?? '',
                        'work_type' => $workType,
                    ];
                }
            } catch (\Throwable $e) {
                Log::error("Gagal simpan report dari klarifikasi: {$e->getMessage()} at line {$e->getLine()} in {$e->getFile()}");
            }
        }

        return $savedReports;
    }

    /**
     * Buat teks ringkasan akhir untuk dikirim ke user
     * Jika $savedReports diberikan (dari saveAllReports), tampilkan ID Report untuk copy-paste
     */
    public static function buildSummaryMessage(array $summary, ?Employee $employee = null, array $savedReports = []): string
    {
        $msg = "\xF0\x9F\x93\x8B <b>Semua laporan selesai diproses!</b>\n\n";
        if ($employee) {
            $department = $employee->department ?? '';
            $msg .= "Teknisi: {$employee->name}" . ($department ? " ({$department})" : '') . "\n";
        }
        $msg .= "Total item: {$summary['total']}\n";
        $msg .= "Terkonfirmasi: {$summary['resolved']}\n";
        $msg .= "Area: {$summary['area']}\n";
        $msg .= "Equipment Baru: {$summary['new_equipment']}\n";
        $msg .= "Tidak dikenal: {$summary['unidentified']}\n";
        $msg .= "Dilewati: {$summary['skipped']}\n\n";
        $msg .= "----------------------------------------\n";

        foreach ($summary['items'] as $i => $item) {
            $workType = $item['work_type'] ?? 'unknown';
            $icon = match($workType) {
                'equipment' => "\xE2\x9C\x85",
                'area' => "\xF0\x9F\x93\x8D",
                'new_equipment' => "\xF0\x9F\x94\xA7",
                'manual' => "\xE2\x9C\x8F\xEF\xB8\x8F",
                'skipped' => "\xE2\x9C\x82\xEF\xB8\x8F",
                'unknown', 'unidentified' => "\xE2\x9D\x8C",
                default => "\xE2\x9D\x93",
            };

            $msg .= "{$icon} Item {$item['index']}: ";

            switch ($workType) {
                case 'equipment':
                    $msg .= "{$item['asset_code']} - {$item['asset_description']}";
                    if ($item['location']) {
                        $msg .= " ({$item['location']})";
                    }
                    break;
                case 'area':
                    $msg .= "PEKERJAAN AREA";
                    break;
                case 'new_equipment':
                    $msg .= "EQUIPMENT BARU (perlu didaftarkan)";
                    break;
                case 'manual':
                    $msg .= "MANUAL: {$item['asset_description']}";
                    break;
                case 'skipped':
                    $msg .= "Dilewati";
                    break;
                default:
                    $msg .= "Equipment tidak dikenal";
                    break;
            }
            $msg .= "\n";
        }

        $needsReview = $summary['new_equipment'] + $summary['unidentified'] + $summary['skipped'];
        if ($needsReview > 0) {
            $msg .= "\n{$needsReview} laporan perlu review admin. Silakan cek di panel web.\n";
        }

        $msg .= "\nAI belajar: Laporan yang sudah dikonfirmasi akan langsung dikenali lain kali.";

        // ===== DAFTAR ID REPORT UNTUK COPY-PASTE (jika ada savedReports) =====
        if (!empty($savedReports)) {
            $msg .= "\n\n\xF0\x9F\x93\x8C <b>Salin ID untuk dokumentasi:</b>\n\n";
            foreach ($savedReports as $sr) {
                $msg .= "<code>{$sr['telegram_report_id']}</code> - {$sr['action']}\n";
            }
            $msg .= "\n <b>Cara:</b> Reply pesan ini + attach foto.\n";
            $msg .= "Atau kirim foto dengan caption ID di atas.";
        }

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
     * Simpan alias baru dari hasil klarifikasi (learning loop).
     * Alias baru otomatis butuh konfirmasi admin (confirmed_by_admin = false).
     * Jika user memilih asset dari area yang SAMA dengan teks laporan, confidence lebih tinggi.
     * Menyimpan audit log untuk traceability.
     */
    public static function learnAlias(string $originalText, array $selectedAsset, ?int $employeeId): void
    {
        try {
            if (empty($originalText) || empty($selectedAsset['id'])) return;

            // Cari apakah ada kode area di teks original
            $areaInText = '';
            preg_match('/(?<![A-Za-z0-9])(RG[1-9]|BD[1-9]|EPE|CES|TF[1-9])(?![A-Za-z0-9])/i', $originalText, $areaMatch);
            if (!empty($areaMatch[0])) {
                $areaInText = strtoupper($areaMatch[0]);
            }

            // Cek apakah asset yang dipilih berasal dari area yang sama
            $asset = \App\Models\Asset::with('area', 'subArea')->find($selectedAsset['id']);
            $assetArea = '';
            if ($asset) {
                $assetArea = strtoupper($asset->area->name ?? $asset->subArea->name ?? '');
            }

            $areaMatch = !empty($areaInText) && !empty($assetArea) && $areaInText === $assetArea;

            // Jika area tidak cocok, simpan dengan confidence rendah dan perlu review
            $confidenceScore = $areaMatch ? 85 : 40;
            $needsReview = !$areaMatch;

            $alias = AssetAlias::firstOrCreate(
                [
                    'alias' => strtolower(trim($originalText)),
                    'asset_id' => $selectedAsset['id'],
                ],
                [
                    'employee_id' => $employeeId,
                    'confidence_score' => $confidenceScore,
                    'auto_generated' => true,
                    'confirmed_by_admin' => false,
                    'source' => 'clarification',
                ]
            );

            if (!$alias->wasRecentlyCreated) {
                $alias->increment('usage_count');
                // Hanya naikkan confidence jika area cocok
                if ($areaMatch) {
                    $alias->increment('confidence_score', 2);
                    if ($alias->confidence_score > 100) {
                        $alias->update(['confidence_score' => 100]);
                    }
                }
            }

            // === SIMPAN AUDIT LOG ===
            try {
                $employee = $employeeId ? \App\Models\Employee::find($employeeId) : null;

                \App\Models\AiAliasAuditLog::create([
                    'asset_alias_id' => $alias->id,
                    'alias' => strtolower(trim($originalText)),
                    'asset_id' => $selectedAsset['id'],
                    'asset_code' => $selectedAsset['tech_ident_no'] ?? '',
                    'asset_description' => $selectedAsset['description'] ?? '',
                    'original_text' => $originalText,
                    'keywords_used' => [], // Akan diisi nanti dari extractKeywords
                    'area_detected' => $areaInText ?: null,
                    'area_asset' => $assetArea ?: null,
                    'ai_possible_assets' => $selectedAsset['ai_possible_assets'] ?? [],
                    'ai_reasoning' => $selectedAsset['notes'] ?? null,
                    'confidence_score' => $confidenceScore,
                    'area_match' => $areaMatch,
                    'action_taken' => 'user_selected',
                    'employee_id' => $employeeId,
                    'telegram_username' => $employee->name ?? null,
                    'occurred_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("Gagal simpan audit log alias: {$e->getMessage()}");
            }

            $logMsg = "AI belajar: '{$originalText}' -> {$selectedAsset['tech_ident_no']}";
            if (!$areaMatch && !empty($areaInText)) {
                $logMsg .= " [PERLU REVIEW: area {$areaInText} ≠ {$assetArea}]";
            }
            Log::info($logMsg);
        } catch (\Throwable $e) {
            Log::warning("Gagal simpan alias dari klarifikasi: {$e->getMessage()}");
        }
    }

    /**
     * Proses pilihan user berdasarkan format "itemNum.Letter" (1-based)
     * Contoh: "1.A" -> item index 0, opsi A (index 0 dari possible_assets)
     */
    public static function processItemByLetter(string $sessionId, int $itemIndex, string $letter): array
    {
        $session = self::getSession($sessionId);

        if (!$session) {
            return ['success' => false, 'error' => 'Sesi tidak ditemukan'];
        }

        $items = $session['items'] ?? [];
        if (!isset($items[$itemIndex])) {
            return ['success' => false, 'error' => 'Item ' . ($itemIndex + 1) . ' tidak ditemukan'];
        }

        $item = &$items[$itemIndex];

        if (!empty($item['resolved_asset_id'])) {
            return ['success' => false, 'error' => 'Item ' . ($itemIndex + 1) . ' sudah dikonfirmasi'];
        }

        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $assetIndex = array_search(strtoupper($letter), $letters);

        if ($assetIndex === false) {
            return ['success' => false, 'error' => 'Huruf ' . $letter . ' tidak dikenal'];
        }

        $possibleAssets = $item['possible_assets'] ?? [];
        if (!isset($possibleAssets[$assetIndex])) {
            return ['success' => false, 'error' => 'Opsi ' . $letter . ' tidak tersedia'];
        }

        $selectedAsset = $possibleAssets[$assetIndex];

        $item['status'] = 'resolved';
        $item['resolved_asset_id'] = $selectedAsset['id'];
        $item['resolved_asset_code'] = $selectedAsset['tech_ident_no'] ?? '';
        $item['resolved_asset_description'] = $selectedAsset['description'] ?? '';
        $item['resolved_location'] = $selectedAsset['location'] ?? '';
        $item['notes'] = 'Dipilih oleh user';

        $session['items'] = $items;
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'item_index' => $itemIndex,
            'asset' => $selectedAsset,
        ];
    }

    /**
     * Skip semua item yang belum resolved
     */
    public static function skipAllItems(string $sessionId): array
    {
        $session = self::getSession($sessionId);

        if (!$session) {
            return ['success' => false, 'error' => 'Sesi tidak ditemukan'];
        }

        foreach ($session['items'] as &$item) {
            if (empty($item['resolved_asset_id'])) {
                $item['status'] = 'skipped';
                $item['notes'] = 'Dilewati user';
            }
        }

        $session['status'] = 'completed';
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'session' => $session,
        ];
    }
}
