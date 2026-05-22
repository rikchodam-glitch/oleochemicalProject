<?php

namespace App\Services\AI;

use App\Models\Asset;
use App\Models\AssetAlias;
use App\Models\Employee;
use App\Models\MaintenanceReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Session manager untuk klarifikasi AI via Telegram.
 * 
 * Melacak sesi klarifikasi per user/chat:
 * - Opsi apa yang ditawarkan AI
 * - Berapa kali user sudah klarifikasi (max 3x)
 * - Status: waiting_user / resolved / rejected / unidentified
 */
class ClarificationSessionManager
{
    const CACHE_PREFIX = 'clarification:';
    const CACHE_TTL = 1800; // 30 menit
    const MAX_CLARIFICATION_ATTEMPTS = 3;
    const MIN_CONFIDENCE_THRESHOLD = 0.8;

    /**
     * Buat sesi klarifikasi baru
     */
    public static function createSession(int $telegramUserId, int $chatId, array $aiResult, ?Employee $employee = null): array
    {
        $sessionId = self::generateSessionId($telegramUserId);

        $session = [
            'session_id' => $sessionId,
            'telegram_user_id' => $telegramUserId,
            'chat_id' => $chatId,
            'employee_id' => $employee?->id,
            'original_text' => $aiResult['normalized_text'] ?? $aiResult['summary'] ?? '',
            'raw_text' => $aiResult['raw_text'] ?? '',
            'possible_assets' => $aiResult['possible_assets'] ?? [],
            'clarification_question' => $aiResult['clarification_question'] ?? 'Pilih equipment yang dimaksud:',
            'confidence' => $aiResult['confidence'] ?? 0,
            'attempts' => 0,
            'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
            'status' => 'waiting_user', // waiting_user | resolved | rejected | unidentified
            'resolved_asset_id' => null,
            'resolved_asset_code' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return $session;
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
     * Proses balasan user — cari apakah user memilih salah satu opsi
     */
    public static function processUserReply(string $sessionId, string $userMessage): array
    {
        $session = self::getSession($sessionId);
        if (!$session) {
            return [
                'success' => false,
                'error' => 'Sesi tidak ditemukan atau sudah kadaluarsa.',
            ];
        }

        // Increment attempts
        $session['attempts']++;
        $session['updated_at'] = now()->toIso8601String();

        // Cek apakah user memilih salah satu opsi
        $selectedAsset = self::matchUserSelection($userMessage, $session['possible_assets']);

        if ($selectedAsset) {
            // Resolved!
            $session['status'] = 'resolved';
            $session['resolved_asset_id'] = $selectedAsset['id'];
            $session['resolved_asset_code'] = $selectedAsset['tech_ident_no'];
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            // Simpan alias baru untuk pembelajaran AI
            self::learnAlias($session, $selectedAsset);

            return [
                'success' => true,
                'resolved' => true,
                'asset' => $selectedAsset,
                'attempts' => $session['attempts'],
                'session' => $session,
            ];
        }

        // Cek apakah sudah mencapai max attempts
        if ($session['attempts'] >= self::MAX_CLARIFICATION_ATTEMPTS) {
            $session['status'] = 'unidentified';
            Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

            return [
                'success' => true,
                'resolved' => false,
                'unidentified' => true,
                'attempts' => $session['attempts'],
                'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
                'session' => $session,
                'error' => 'Maaf, setelah 3 kali percobaan masih belum ketemu. Laporan akan diteruskan ke admin.',
            ];
        }

        // Masih ada kesempatan
        Cache::put(self::CACHE_PREFIX . $sessionId, $session, self::CACHE_TTL);

        return [
            'success' => true,
            'resolved' => false,
            'attempts' => $session['attempts'],
            'max_attempts' => self::MAX_CLARIFICATION_ATTEMPTS,
            'remaining_attempts' => self::MAX_CLARIFICATION_ATTEMPTS - $session['attempts'],
            'session' => $session,
            'error' => 'Pilihan tidak dikenali. Silakan ketik angka atau kode equipment yang tersedia.',
        ];
    }

    /**
     * Cocokkan pesan user dengan opsi yang ditawarkan
     */
    protected static function matchUserSelection(string $userMessage, array $possibleAssets): ?array
    {
        $msg = trim($userMessage);

        // Coba cocokkan dengan angka (1, 2, 3)
        if (is_numeric($msg)) {
            $index = (int)$msg - 1;
            if (isset($possibleAssets[$index])) {
                return $possibleAssets[$index];
            }
        }

        // Coba cocokkan dengan huruf (A, B, C)
        if (preg_match('/^[a-zA-Z]$/', $msg)) {
            $index = ord(strtoupper($msg)) - ord('A');
            if (isset($possibleAssets[$index])) {
                return $possibleAssets[$index];
            }
        }

        // Coba cocokkan dengan tech_ident_no atau ID
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
     * Hapus sesi (setelah selesai)
     */
    public static function destroySession(string $sessionId): void
    {
        Cache::forget(self::CACHE_PREFIX . $sessionId);
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
    protected static function learnAlias(array $session, array $selectedAsset): void
    {
        try {
            $originalText = $session['original_text'] ?? $session['raw_text'] ?? '';
            if (empty($originalText) || empty($selectedAsset['id'])) return;

            // Cari atau buat alias
            $alias = AssetAlias::firstOrCreate(
                [
                    'alias' => strtolower(trim($originalText)),
                    'asset_id' => $selectedAsset['id'],
                ],
                [
                    'employee_id' => $session['employee_id'],
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

            Log::info("AI belajar alias baru dari klarifikasi: '{$originalText}' → {$selectedAsset['tech_ident_no']}");
        } catch (\Throwable $e) {
            Log::warning("Gagal simpan alias dari klarifikasi: {$e->getMessage()}");
        }
    }

    /**
     * Simpan laporan hasil klarifikasi ke database maintenance_reports
     */
    public static function saveReportFromClarification(array $session, array $selectedAsset): ?MaintenanceReport
    {
        try {
            $employee = null;
            if ($session['employee_id']) {
                $employee = Employee::find($session['employee_id']);
            }

            $report = MaintenanceReport::create([
                'employee_id' => $session['employee_id'],
                'asset_id' => $selectedAsset['id'],
                'asset_code' => $selectedAsset['tech_ident_no'] ?? '',
                'action_taken' => $session['original_text'] ?? $session['raw_text'] ?? 'Laporan via Telegram',
                'status' => 'done',
                'report_date' => now(),
                'shift' => 'pagi',
                'is_from_telegram' => true,
                'clarification_status' => 'resolved',
                'clarification_session_id' => $session['session_id'],
                'original_raw_text' => $session['raw_text'] ?? $session['original_text'] ?? '',
            ]);

            Log::info("Laporan tersimpan dari klarifikasi Telegram: {$report->id}");

            return $report;
        } catch (\Throwable $e) {
            Log::error("Gagal simpan laporan dari klarifikasi: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Simpan laporan unidentified (max attempts exceeded)
     */
    public static function saveUnidentifiedReport(array $session): ?MaintenanceReport
    {
        try {
            $report = MaintenanceReport::create([
                'employee_id' => $session['employee_id'],
                'asset_id' => null,
                'asset_code' => 'UNIDENTIFIED',
                'action_taken' => $session['original_text'] ?? $session['raw_text'] ?? 'Laporan tidak teridentifikasi',
                'status' => 'pending',
                'report_date' => now(),
                'shift' => 'pagi',
                'is_from_telegram' => true,
                'clarification_status' => 'unidentified',
                'clarification_session_id' => $session['session_id'],
                'original_raw_text' => $session['raw_text'] ?? $session['original_text'] ?? '',
                'notes' => '⚠️ Laporan tidak teridentifikasi setelah 3x klarifikasi. Perlu review admin.',
            ]);

            Log::info("Laporan unidentified tersimpan: {$report->id}");

            return $report;
        } catch (\Throwable $e) {
            Log::error("Gagal simpan unidentified report: {$e->getMessage()}");
            return null;
        }
    }
}
