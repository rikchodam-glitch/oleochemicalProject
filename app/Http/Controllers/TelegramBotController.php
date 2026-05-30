<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\MaintenanceReport;
use App\Models\TelegramBlacklist;
use App\Models\TelegramBotLog;
use App\Models\TelegramSetting;
use App\Services\AI\AiGatewayService;
use App\Services\Telegram\TelegramPhotoHandler;
use App\Services\Telegram\TelegramReportParser;
use App\Services\Telegram\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramBotController extends Controller
{
    protected TelegramService $telegram;
    protected TelegramReportParser $parser;
    protected TelegramPhotoHandler $photoHandler;

    public function __construct()
    {
        $this->telegram = new TelegramService();
        $this->parser = new TelegramReportParser();
        $this->photoHandler = new TelegramPhotoHandler($this->telegram);
    }

    /**
     * Webhook endpoint  menerima semua update dari Telegram
     */
    public function webhook(Request $request)
    {
        $update = $request->all();
        $message = $update['message'] ?? null;
        $callbackQuery = $update['callback_query'] ?? null;
        $updateId = $update['update_id'] ?? null;

        //  HANDLE CALLBACK QUERY (tombol inline ditekan)
        if ($callbackQuery) {
            return $this->handleCallbackQuery($callbackQuery);
        }

        if (!$message) {
            return response()->json(['status' => 'ok']);
        }

        $chatId = $message['chat']['id'] ?? null;
        $username = $message['from']['username'] ?? null;
        $text = $message['text'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (!$chatId) {
            return response()->json(['status' => 'ok']);
        }

        // CEK DUPLIKASI VIA UPDATE_ID  paling akurat
        if ($updateId) {
            $alreadyProcessed = TelegramBotLog::where('telegram_update_id', $updateId)->exists();
            if ($alreadyProcessed) {
                Log::info("Duplicate update_id skipped: {$updateId}");
                return response()->json(['status' => 'duplicate_skipped']);
            }
        }

        // CEK BLACKLIST
        if (TelegramBlacklist::where('telegram_chat_id', $chatId)->exists()) {
            $this->telegram->sendMessage($chatId, " Anda telah diblokir dari bot ini. Hubungi admin untuk informasi lebih lanjut.");
            return response()->json(['status' => 'blocked']);
        }

        // CEK STATUS BOT
        $botStatus = TelegramSetting::getValue('bot_status', 'active');
        if ($botStatus === 'maintenance') {
            $this->telegram->sendMessage($chatId, " Bot sedang dalam perawatan. Silakan coba lagi nanti.");
            return response()->json(['status' => 'maintenance']);
        }

        // IDENTIFIKASI USER
        $employee = Employee::where('telegram_id', $chatId)->first();

        // LOG SEMUA PESAN MASUK
        $incomingMsg = $text ?? '(photo)';
        if (isset($message['photo'])) {
            $fileId = $message['photo'][count($message['photo'])-1]['file_id'] ?? '';
            $incomingMsg = " [{$fileId}] " . ($text ?? '');
        }

        $log = TelegramBotLog::create([
            'telegram_update_id' => $updateId,
            'employee_id' => $employee ? $employee->id : null,
            'telegram_chat_id' => $chatId,
            'telegram_username' => $username,
            'incoming_message' => $incomingMsg,
            'message_type' => $this->detectMessageType($message),
            'parsing_status' => 'pending',
        ]);

        try {
            // Routing berdasarkan tipe pesan
            if (isset($message['photo'])) {
                $this->handlePhotoMessage($message, $chatId, $employee, $log);
            } elseif ($text && str_starts_with($text, '/')) {
                $this->handleCommand($text, $chatId, $username, $employee, $log);
            } elseif ($text && $employee) {
                $this->handleReportText($text, $chatId, $employee, $log);
            } elseif ($text && !$employee) {
                $this->handleUnregisteredUser($text, $chatId, $username, $log);
            }

            $log->update(['parsing_status' => 'success']);
        } catch (\Throwable $e) {
            $log->update([
                'parsing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage($chatId,
                " Maaf, terjadi kesalahan internal. Silakan coba lagi.\n\n" .
                "Kode error: #ERR" . $log->id
            );

            Log::error('Telegram Bot Error: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'log_id' => $log->id,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle callback query  user menekan tombol inline keyboard
     */
    protected function handleCallbackQuery(array $callbackQuery): \Illuminate\Http\JsonResponse
    {
        $callbackId = $callbackQuery['id'] ?? '';
        $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
        $messageId = $callbackQuery['message']['message_id'] ?? 0;
        $data = $callbackQuery['data'] ?? '';
        $fromId = $callbackQuery['from']['id'] ?? 0;

        // Parse callback data: prefix:session_id:value
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $sessionId = $parts[1] ?? '';
        $value = $parts[2] ?? '';

        // Cari session
        $session = \App\Services\AI\ClarificationSessionManager::getSession($sessionId);
        if (!$session) {
            $this->telegram->answerCallbackQuery($callbackId, 'Sesi telah kadaluarsa', true);
            return response()->json(['status' => 'expired']);
        }

        // Cari employee dari chatId
        $employee = Employee::where('telegram_id', $chatId)->first();

        switch ($action) {
            case 'clarify_item':
                // User memilih salah satu opsi dari inline keyboard (multi-item)
                $this->telegram->answerCallbackQuery($callbackId, "Memilih opsi {$value}...");

                // Proses pilihan via processCurrentItem
                $result = \App\Services\AI\ClarificationSessionManager::processCurrentItem(
                    $sessionId,
                    $value // "1", "2", "3"
                );

                if (!$result['success']) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        " " . ($result['error'] ?? 'Terjadi kesalahan.')
                    );
                    return response()->json(['status' => 'error']);
                }

                $session = $result['session'];

                //  ITEM RESOLVED
                if ($result['resolved']) {
                    $asset = $result['asset'];
                    $confirmText = " <b>Item " . ($result['item_index'] + 1) . " dikonfirmasi!</b>\n\n" .
                        "Equipment: <b>{$asset['tech_ident_no']}</b>\n" .
                        "Deskripsi: {$asset['description']}\n";
                    if (!empty($asset['location'])) {
                        $confirmText .= "Lokasi: {$asset['location']}\n";
                    }
                    $this->telegram->editMessageText($chatId, $messageId, $confirmText);
                }

                // ITEM SKIPPED
                if (!empty($result['skipped'])) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        " <b>Item " . ($result['item_index'] + 1) . " dilewati.</b>"
                    );
                }

                // ITEM UNIDENTIFIED
                if (!empty($result['unidentified'])) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        "<b>Item " . ($result['item_index'] + 1) . " tidak teridentifikasi.</b>\n" .
                        "Setelah " . $result['attempts'] . " kali percobaan."
                    );
                }

                // SEMUA SELESAI
                if ($result['completed']) {
                    $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
                    $savedReports = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);
                    $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee, $savedReports);

                    if (!empty($savedReports)) {
                        $firstReport = $savedReports[0];
                        TelegramBotLog::where('telegram_chat_id', $chatId)
                            ->latest()
                            ->first()
                            ?->update(['maintenance_report_id' => $firstReport['id']]);
                    }

                    $this->telegram->sendMessage($chatId, $summaryMsg);
                    \App\Services\AI\ClarificationSessionManager::destroySession($sessionId);
                    return response()->json(['status' => 'completed']);
                }

                // LANJUT KE ITEM BERIKUTNYA
                $nextIdx = $result['next_item_index'];
                $remaining = $result['remaining_items'];
                $total = $result['total_items'];

                $nextItem = \App\Services\AI\ClarificationSessionManager::getCurrentItem($session);
                $nextMsg = \App\Services\AI\ClarificationSessionManager::buildClarificationMessage($session, $nextItem);

                $nextMsg = "<b>Lanjut Item " . ($nextIdx + 1) . " dari {$total}</b>\n\n" . $nextMsg;

                if (!empty($nextItem['possible_assets'])) {
                    $buttons = \App\Services\AI\ClarificationSessionManager::buildClarificationKeyboard($session, $nextItem);
                    $this->telegram->sendMessageWithKeyboard($chatId, $nextMsg, $buttons);
                } else {
                    $this->telegram->sendMessage($chatId, $nextMsg);
                }
                break;

            case 'clarify_skip':
                // User pilih lewati item
                $this->telegram->answerCallbackQuery($callbackId, "Melewati item...");

                $result = \App\Services\AI\ClarificationSessionManager::processCurrentItem(
                    $sessionId,
                    'lewati'
                );

                if (!$result['success']) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        " " . ($result['error'] ?? 'Terjadi kesalahan.')
                    );
                    return response()->json(['status' => 'error']);
                }

                $session = $result['session'];

                $this->telegram->editMessageText($chatId, $messageId,
                    " <b>Item " . ($result['item_index'] + 1) . " dilewati.</b>"
                );

                if ($result['completed']) {
                    $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
                    $savedReports = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);
                    $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee, $savedReports);

                    if (!empty($savedReports)) {
                        $firstReport = $savedReports[0];
                        TelegramBotLog::where('telegram_chat_id', $chatId)
                            ->latest()
                            ->first()
                            ?->update(['maintenance_report_id' => $firstReport['id']]);
                    }

                    $this->telegram->sendMessage($chatId, $summaryMsg);
                    \App\Services\AI\ClarificationSessionManager::destroySession($sessionId);
                    return response()->json(['status' => 'completed']);
                }

                $nextItem = \App\Services\AI\ClarificationSessionManager::getCurrentItem($session);
                $total = $result['total_items'];
                $nextIdx = $result['next_item_index'];

                $nextMsg = \App\Services\AI\ClarificationSessionManager::buildClarificationMessage($session, $nextItem);
                $nextMsg = "<b>Lanjut Item " . ($nextIdx + 1) . " dari {$total}</b>\n\n" . $nextMsg;

                if (!empty($nextItem['possible_assets'])) {
                    $buttons = \App\Services\AI\ClarificationSessionManager::buildClarificationKeyboard($session, $nextItem);
                    $this->telegram->sendMessageWithKeyboard($chatId, $nextMsg, $buttons);
                } else {
                    $this->telegram->sendMessage($chatId, $nextMsg);
                }
                break;

            default:
                $this->telegram->answerCallbackQuery($callbackId, 'Aksi tidak dikenal');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle command dari user
     */
    protected function handleCommand(string $text, string $chatId, ?string $username, ?Employee $employee, TelegramBotLog $log): void
    {
        $command = strtok($text, ' ');
        $args = trim(substr($text, strlen($command)));

        $log->update(['bot_command' => $command]);

        switch ($command) {
            case '/start':
                $this->sendWelcome($chatId);
                break;

            case '/help':
                $this->sendHelp($chatId);
                break;

            case '/register':
                $this->handleRegister($chatId, $username, $log);
                break;

            case '/status':
                $this->handleStatusCommand($args, $chatId, $log);
                break;

            case '/me':
                if ($employee) {
                    $this->telegram->sendMessage($chatId,
                        "👤 <b>Profil Anda</b>\n\n" .
                        "Nama: {$employee->name}\n" .
                        "NIK: {$employee->nik}\n" .
                        "Departemen: {$employee->department}\n" .
                        "Shift: {$employee->shift}\n" .
                        "Status:   Terdaftar"
                    );
                } else {
                    $this->telegram->sendMessage($chatId, "  Anda belum terdaftar. Ketik /register untuk mendaftar.");
                }
                break;

            default:
                $this->telegram->sendMessage($chatId,
                    "  Perintah tidak dikenal. Ketik /help untuk bantuan."
                );
                break;
        }
    }

    /**
     * Handle user yang belum terdaftar
     */
    protected function handleUnregisteredUser(string $text, string $chatId, ?string $username, TelegramBotLog $log): void
    {
        $this->telegram->sendMessage($chatId,
            "  <b>Anda belum terdaftar!</b>\n\n" .
            "Untuk menggunakan bot ini, silakan daftar terlebih dahulu:\n" .
            "Ketik /register untuk memulai pendaftaran.\n\n" .
            "Atau hubungi admin untuk mendaftarkan nomor HP Anda."
        );
    }

    /**
     * Handle registrasi user
     */
    protected function handleRegister(string $chatId, ?string $username, TelegramBotLog $log): void
    {
        $this->telegram->sendMessage($chatId,
            "  <b>Pendaftaran Teknisi</b>\n\n" .
            "Silakan kirim <b>nomor HP</b> Anda yang terdaftar di sistem.\n\n" .
            "Contoh: <code>08123456789</code> atau <code>628123456789</code>\n\n" .
            "Nomor ini harus sama dengan yang didaftarkan oleh admin."
        );

        // Simpan state bahwa user sedang proses register
        // (akan di-handle di pesan berikutnya)
    }

    /**
     * Handle laporan teks dari user terdaftar
     * Membedakan:
     * 1. Report Rangkuman: format "Report shift X\nDD/MM/YYYY\n1. Aksi - done"
     *    -> AI analisa multiple items, batch 1.A 2.B jika ambigu
     * 2. Report Harian: teks bebas 1 equipment (tanpa shift/date)
     *    -> Shift & tanggal otomatis dari jam kirim
     *    -> AI langsung mapping, simpan, tampilkan ID
     */
    protected function handleReportText(string $text, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        $this->telegram->sendChatAction($chatId);

        // Cek apakah ini nomor HP untuk registrasi
        if (preg_match('/^(0|62)\d{8,15}$/', trim($text))) {
            $this->processRegistration($text, $chatId, $log);
            return;
        }

        // Cek apakah user sedang dalam sesi klarifikasi
        // Cek apakah user sedang dalam sesi klarifikasi
        $activeSession = \App\Services\AI\ClarificationSessionManager::getActiveSession((int)$chatId);
        if ($activeSession && $activeSession['status'] === 'waiting_user') {
            $this->processClarificationReply($text, $chatId, $employee, $log, $activeSession);
            return;
        }

        // DETEKSI JENIS: Report Rangkuman atau tolak?
        $isRangkuman = preg_match('/^Report\s*shift\s*\d+/i', trim($text));

        if ($isRangkuman) {
            $this->handleReportRangkuman($text, $chatId, $employee, $log);
        } else {
            // Cek format jawaban klarifikasi (1.A, 1.A 2.B)
            if (preg_match('/^[\d\s\.\:\-]+\s*[A-Ha-h]/', trim($text))) {
                $activeSession = \App\Services\AI\ClarificationSessionManager::getActiveSession((int)$chatId);
                if ($activeSession && $activeSession['status'] === 'waiting_user') {
                    $this->processClarificationReply($text, $chatId, $employee, $log, $activeSession);
                    return;
                }
            }

            // Teks biasa = bukan format dikenali
            $this->telegram->sendMessage($chatId,
                "Format tidak dikenali.\n\n" .
                "Untuk laporan HARIAN:\n" .
                "Kirim FOTO + caption keterangan pekerjaan.\n\n" .
                "Untuk laporan RANGKUMAN:\n" .
                "Gunakan format:\n" .
                "Report shift 1\n" .
                "DD/MM/YYYY\n" .
                "1. Aksi perbaikan - done\n" .
                "2. Aksi lain (continue)"
            );
        }
    }

    /**
     * Simpan 1 report harian langsung
     */
    protected function saveSingleReport(array $parsed, ?\App\Models\Asset $asset, ?array $aiResult, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        $dateStr = $parsed['date']->format('Ymd');

        // Generate ID
        $lastSeq = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
            ->orderBy('telegram_report_id', 'desc')
            ->value('telegram_report_id');

        $nextSeq = 1;
        if ($lastSeq && preg_match('/-(\d{3})$/', $lastSeq, $m)) {
            $nextSeq = (int)$m[1] + 1;
        }
        $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

        DB::beginTransaction();
        try {
            $report = MaintenanceReport::create([
                'asset_id' => $asset ? $asset->id : null,
                'employee_id' => $employee->id,
                'raw_text' => $parsed['raw_text'],
                'action_taken' => $parsed['items'][0]['action'],
                'status' => $parsed['items'][0]['status'],
                'report_date' => $parsed['date'],
                'shift' => $parsed['shift'],
                'source' => 'telegram',
                'telegram_report_id' => $telegramReportId,
                'ai_confidence' => $aiResult['items'][0]['confidence'] ?? null,
                'ai_suggested' => $asset !== null,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $log->update(['maintenance_report_id' => $report->id]);

        $assetName = $asset ? $asset->tech_ident_no : '  (tidak dikenal)';
        $confText = $aiResult && isset($aiResult['items'][0]['confidence'])
            ? '  ' . round($aiResult['items'][0]['confidence'] * 100) . '%'
            : '';

        // Kirim konfirmasi + ID
        $response = "  <b>Laporan direkam!</b>\n\n";
        $response .= "Shift: {$parsed['shift']} | {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Alat: {$assetName}{$confText}\n";
        $response .= "Aksi: {$parsed['items'][0]['action']}\n\n";
        $response .= "  <b>Kirim foto  salin ID di bawah:</b>\n\n";
        $response .= "<code>{$telegramReportId}</code>\n\n";
        $response .= " <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $response .= "Atau kirim foto dengan caption ID di atas sebagai caption.";

        $this->telegram->sendMessage($chatId, $response);
    }
    protected function handleReportHarianFromPhoto(array $message, string $caption, string $chatId, Employee $employee, TelegramBotLog $log): void
{
    $now = now();
    $hour = (int)$now->format('H');
    $shift = match(true) {
        $hour >= 8 && $hour < 16 => '1',
        $hour >= 16 || $hour < 0 => '2',
        default => 'Malam',
    };
    $date = $now;

    $this->telegram->sendMessage($chatId,
        "Laporan Harian (dari foto)\n" .
        "Shift: {$shift} | {$date->format('d/m/Y')}\n" .
        "Caption: {$caption}\n" .
        "Menganalisa..."
    );

    $gateway = new AiGatewayService();
    $gateway->withContext($employee);

    try {
        $singleText = "Item 1: " . $caption . " (done)";
        $aiResult = $gateway->analyzeWithClarification($singleText, $employee);
    } catch (\Throwable $e) {
        Log::warning("AI Harian (foto) gagal: {$e->getMessage()}");
        $aiResult = null;
    }

    $resolvedAsset = null;
    $aiItem = null;

    if ($aiResult && isset($aiResult['items']) && !empty($aiResult['items'])) {
        $aiItem = $aiResult['items'][0];
        $conf = $aiItem['confidence'] ?? 0;
        if ($conf >= 0.8 && !empty($aiItem['suggested_asset_id'])) {
            $resolvedAsset = \App\Models\Asset::find($aiItem['suggested_asset_id']);
        }
    }

    $dateStr = $date->format('Ymd');
    $lastSeq = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
        ->orderBy('telegram_report_id', 'desc')
        ->value('telegram_report_id');
    $nextSeq = 1;
    if ($lastSeq && preg_match('/-(\d{3})$/', $lastSeq, $m)) {
        $nextSeq = (int)$m[1] + 1;
    }
    $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

    DB::beginTransaction();
    try {
        $report = MaintenanceReport::create([
            'asset_id' => $resolvedAsset ? $resolvedAsset->id : null,
            'employee_id' => $employee->id,
            'raw_text' => $caption,
            'action_taken' => $caption,
            'status' => 'done',
            'report_date' => $date,
            'shift' => $shift,
            'source' => 'telegram',
            'telegram_report_id' => $telegramReportId,
            'ai_confidence' => $aiItem['confidence'] ?? null,
            'ai_suggested' => $resolvedAsset !== null,
        ]);

        // Attach foto langsung
        $photoPath = $this->photoHandler->handlePhoto($message, $chatId);
        if ($photoPath) {
            $this->photoHandler->attachPhotoToReport($report, $photoPath);
        }

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        throw $e;
    }

    $log->update(['maintenance_report_id' => $report->id]);
    $assetName = $resolvedAsset ? $resolvedAsset->tech_ident_no : '(tidak dikenal)';
    $this->telegram->sendMessage($chatId,
        "Laporan tersimpan!\n\n" .
        "ID: {$telegramReportId}\n" .
        "Shift: {$shift} | {$date->format('d/m/Y')}\n" .
        "Alat: {$assetName}\n" .
        "Aksi: {$caption}\n\n" .
        "Foto otomatis ter-attach."
    );
}
    protected function saveResolvedItemsAndGetSummary(array $sessionItems, string $chatId, Employee $employee,
TelegramBotLog $log, array $parsed, string $rawText): string
    {
        $dateStr = $parsed['date']->format('Ymd');
        $reportIds = [];

        DB::beginTransaction();
        try {
            foreach ($sessionItems as $item) {
                $lastSeq = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
                    ->orderBy('telegram_report_id', 'desc')
                    ->value('telegram_report_id');
                $nextSeq = 1;
                if ($lastSeq && preg_match('/-(\d{3})$/', $lastSeq, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

                $report = MaintenanceReport::create([
                    'asset_id' => $item['resolved_asset_id'],
                    'employee_id' => $employee->id,
                    'raw_text' => $rawText,
                    'action_taken' => $item['original_action'] ?? $item['raw_text'],
                    'status' => $item['original_status'] ?? 'done',
                    'report_date' => $parsed['date'],
                    'shift' => $parsed['shift'],
                    'source' => 'telegram',
                    'telegram_report_id' => $telegramReportId,
                    'ai_confidence' => $item['confidence'] ?? null,
                    'ai_suggested' => true,
                ]);
                $reportIds[] = $report;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $log->update(['maintenance_report_id' => $reportIds[0]->id ?? null]);

        $response = "  <b>Rangkuman tersimpan!</b>\n\n";
        $response .= "Shift: {$parsed['shift']} | {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Teknisi: {$employee->name}\n\n";

        foreach ($reportIds as $report) {
            $assetName = $report->asset ? $report->asset->tech_ident_no : '(tidak dikenal)';
            $response .= "  <b>{$report->telegram_report_id}</b>\n";
            $response .= "   " . $report->action_taken . " -> " . $assetName . "\n\n";
        }

        // Daftar ID untuk copy-paste
        $response .= "  <b>Kirim foto  salin ID di bawah:</b>\n\n";
        foreach ($reportIds as $report) {
            $response .= "<code>{$report->telegram_report_id}</code>\n";
        }
        $response .= "\n <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $response .= "Atau kirim foto dengan caption ID di atas.";

        return $response;
    }
    protected function sendNextClarificationItem(array $session, string $chatId): void
    {
        $totalItems = $session['total_items'] ?? 0;
        $items = $session['items'] ?? [];

        // Cari item pending pertama
        $targetIdx = null;
        foreach ($items as $i => $it) {
            if (($it['status'] ?? '') === 'pending') {
                $targetIdx = $i;
                break;
            }
        }

        if ($targetIdx === null) {
            // Semua selesai  set completed dan kirim summary dengan ID reports
            $session['status'] = 'completed';
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $session['session_id'],
                $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );
            $savedReports = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);
            $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);

            // Cari employee untuk ditampilkan di summary
            $employeeSummary = null;
            if (!empty($session['employee_id'])) {
                $employeeSummary = \App\Models\Employee::find($session['employee_id']);
            }

            $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employeeSummary, $savedReports);
            $this->telegram->sendMessage($chatId, $summaryMsg);
            \App\Services\AI\ClarificationSessionManager::destroySession($session['session_id']);
            return;
        }

        $currentItem = $items[$targetIdx];

        $session['current_item_index'] = $targetIdx;
        \Illuminate\Support\Facades\Cache::put(
            \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $session['session_id'],
            $session,
            \App\Services\AI\ClarificationSessionManager::CACHE_TTL
        );

        // === CEK: Sedang menunggu durasi ===
        if ($currentItem['awaiting_duration'] ?? false) {
            $this->telegram->sendMessage($chatId,
                "Item " . ($targetIdx + 1) . "/" . $totalItems . " sudah dikonfirmasi.\n" .
                "Berapa durasi pengerjaan? (contoh: 2, 1.5, 30 menit, atau 0 untuk lewati)"
            );
            return;
        }
        // === Jika ini item pertama dan sudah resolved (auto-resolve/area), konfirmasi dulu ===
        // Cek apakah ada item resolved sebelum item pending pertama
        $resolvedBefore = $targetIdx;
        if ($resolvedBefore > 0) {
            // Ada item yang sudah resolved otomatis  informasikan user
            $resolvedItems = [];
            for ($i = 0; $i < $targetIdx; $i++) {
                if ($items[$i]['status'] === 'resolved' && $items[$i]['work_type'] === 'area') {
                    $resolvedItems[] = ($i + 1) . ". " . e($items[$i]['raw_text']) . " -> PEKERJAAN AREA";
                } elseif ($items[$i]['status'] === 'resolved' && $items[$i]['work_type'] === 'equipment') {
                    $resolvedItems[] = ($i + 1) . ". " . e($items[$i]['raw_text']) . " -> " . $items[$i]['resolved_asset_code'];
                }
            }
            if (!empty($resolvedItems)) {
                $this->telegram->sendMessage($chatId,
                    "Item sebelumnya otomatis dikenali:\n" . implode("\n", $resolvedItems)
                );
            }
        }
        // === KIRIM PESAN PILIH EQUIPMENT ===
        // Gunakan buildClarificationMessage dari SessionManager yang sudah include opsi manual (#)
        $msg = \App\Services\AI\ClarificationSessionManager::buildClarificationMessage($session, $currentItem);
        $this->telegram->sendMessage($chatId, $msg);
    }
        /**
     * Report Rangkuman - format "Report shift X\nDD/MM/YYYY\n1. Aksi - done"
     * Multiple items, AI analisa BATCH lalu klarifikasi SEQUENTIAL per item
     * Auto-resolve jika confidence >= 95%, tanpa perlu klarifikasi user
     */
    protected function handleReportRangkuman(string $text, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        $parsed = $this->parser->parse($text);

        if (empty($parsed['items'])) {
            $this->telegram->sendMessage($chatId,
                "Format rangkuman tidak dikenali.\n\n" .
                "Gunakan format:\n" .
                "Report shift 1\n" .
                "DD/MM/YYYY\n" .
                "1. Aksi perbaikan - done\n" .
                "2. Aksi lain (continue)"
            );
            return;
        }

        $this->telegram->sendMessage($chatId,
            "Rangkuman - " . count($parsed['items']) . " item ditemukan.\n" .
            "AI sedang menganalisa..."
        );

        $gateway = new AiGatewayService();
        $gateway->withContext($employee);

        $allItemsText = "Laporan maintenance:\nShift: " . $parsed['shift'] . "\nTanggal: " . $parsed['date']->format('d/m/Y') . "\n\nItems:\n";
        foreach ($parsed['items'] as $i => $item) {
            $allItemsText .= ($i+1) . ". " . $item['action'] . " (" . $item['status'] . ")\n";
        }

        try {
            $aiResult = $gateway->analyzeWithClarification($allItemsText, $employee);
        } catch (\Throwable $e) {
            Log::warning("AI Rangkuman gagal: {$e->getMessage()}");
            $aiResult = null;
        }

        // ===== BUILD SESSION ITEMS =====
        $sessionItems = [];

        if ($aiResult && isset($aiResult['items']) && !empty($aiResult['items'])) {
            $aiItems = $aiResult['items'];

            foreach ($parsed['items'] as $index => $origItem) {
                // Ambil item dari AI jika ada, jika tidak gunakan data dari parser
                $aiItem = $aiItems[$index] ?? null;
                if ($aiItem === null) {
                    // AI tidak memberikan data untuk item ini  fallback
                    $sessionItems[] = [
                        'raw_text' => $origItem['action'],
                        'possible_assets' => [],
                        'confidence' => 0,
                        'status' => 'pending',
                        'work_type' => null,
                        'attempts' => 0,
                        'max_attempts' => \App\Services\AI\ClarificationSessionManager::MAX_CLARIFICATION_ATTEMPTS,
                        'resolved_asset_id' => null,
                        'resolved_asset_code' => null,
                        'resolved_asset_description' => null,
                        'resolved_location' => null,
                        'original_action' => $origItem['action'],
                        'original_status' => $origItem['status'] ?? 'done',
                        'duration_hours' => $origItem['duration_hours'] ?? null,
                        'notes' => 'AI tidak memberikan analisa untuk item ini',
                        'parsed_date' => $parsed['date']->format('Y-m-d'),
                        'parsed_shift' => $parsed['shift'],
                    ];
                    continue;
                }

                $conf = $aiItem['confidence'] ?? 0;
                $suggestedAssetId = $aiItem['suggested_asset_id'] ?? null;

                // Ambil durasi dari parser (jika ada)
                $durationHours = $origItem['duration_hours'] ?? null;

                // AUTO-RESOLVE jika confidence >= 95%
                if ($conf >= 0.95 && !empty($suggestedAssetId)) {
                    $assetModel = \App\Models\Asset::find($suggestedAssetId);
                    if ($assetModel) {
                        $sessionItems[] = [
                            'raw_text' => $origItem['action'] ?? $aiItem['original_text'] ?? '',
                            'possible_assets' => [],
                            'confidence' => $conf,
                            'status' => 'resolved',
                            'work_type' => 'equipment',
                            'attempts' => 0,
                            'max_attempts' => 0,
                            'resolved_asset_id' => $assetModel->id,
                            'resolved_asset_code' => $assetModel->tech_ident_no ?? '',
                            'resolved_asset_description' => $assetModel->description ?? '',
                            'resolved_location' => ($assetModel->area->name ?? '') . ($assetModel->subArea->name ? ' - ' . $assetModel->subArea->name : ''),
                            'original_action' => $origItem['action'] ?? null,
                            'original_status' => $origItem['status'] ?? 'done',
                            'duration_hours' => $durationHours,
                            'notes' => 'Auto-resolved (confidence ' . round($conf * 100) . '%)',
                            'parsed_date' => $parsed['date']->format('Y-m-d'),
                            'parsed_shift' => $parsed['shift'],
                        ];
                        continue;
                    }
                }

                // Item ambigu (< 95%)
                $possibleAssets = $aiItem['possible_assets'] ?? [];
                $isAreaWork = $aiItem['is_area_work'] ?? false;

                if ($isAreaWork) {
                    // AI mengidentifikasi ini sebagai pekerjaan area  langsung resolved
                    $sessionItems[] = [
                        'raw_text' => $origItem['action'] ?? $aiItem['original_text'] ?? '',
                        'possible_assets' => [],
                        'confidence' => $conf,
                        'status' => 'resolved',
                        'work_type' => 'area',
                        'attempts' => 0,
                        'max_attempts' => 0,
                        'resolved_asset_id' => null,
                        'resolved_asset_code' => 'AREA',
                        'resolved_asset_description' => 'Pekerjaan Area',
                        'resolved_location' => '',
                        'original_action' => $origItem['action'] ?? null,
                        'original_status' => $origItem['status'] ?? 'done',
                        'duration_hours' => $durationHours,
                        'notes' => 'AI mengidentifikasi sebagai pekerjaan area',
                        'parsed_date' => $parsed['date']->format('Y-m-d'),
                        'parsed_shift' => $parsed['shift'],
                    ];
                } else {
                    $sessionItems[] = [
                        'raw_text' => $origItem['action'] ?? $aiItem['original_text'] ?? '',
                        'possible_assets' => $possibleAssets,
                        'confidence' => $conf,
                        'status' => 'pending',
                        'work_type' => null,
                        'attempts' => 0,
                        'max_attempts' => \App\Services\AI\ClarificationSessionManager::MAX_CLARIFICATION_ATTEMPTS,
                        'resolved_asset_id' => null,
                        'resolved_asset_code' => null,
                        'resolved_asset_description' => null,
                        'resolved_location' => null,
                        'original_action' => $origItem['action'] ?? null,
                        'original_status' => $origItem['status'] ?? 'done',
                        'duration_hours' => $durationHours,
                        'notes' => null,
                        'parsed_date' => $parsed['date']->format('Y-m-d'),
                        'parsed_shift' => $parsed['shift'],
                    ];
                }
            }
        } else {
            // AI GAGAL
            foreach ($parsed['items'] as $index => $item) {
                $sessionItems[] = [
                    'raw_text' => $item['action'],
                    'possible_assets' => [],
                    'confidence' => 0,
                    'status' => 'pending',
                    'work_type' => null,
                    'attempts' => 0,
                    'max_attempts' => \App\Services\AI\ClarificationSessionManager::MAX_CLARIFICATION_ATTEMPTS,
                    'resolved_asset_id' => null,
                    'resolved_asset_code' => null,
                    'resolved_asset_description' => null,
                    'resolved_location' => null,
                    'original_action' => $item['action'],
                    'original_status' => $item['status'] ?? 'done',
                    'duration_hours' => $item['duration_hours'] ?? null,
                    'notes' => 'AI gagal menganalisa',
                    'parsed_date' => $parsed['date']->format('Y-m-d'),
                    'parsed_shift' => $parsed['shift'],
                ];
            }
        }

        // Cek apakah semua auto-resolved
        $allResolved = true;
        $firstPendingIndex = null;
        foreach ($sessionItems as $siIdx => $si) {
            if ($si['status'] !== 'resolved') {
                $allResolved = false;
                if ($firstPendingIndex === null) $firstPendingIndex = $siIdx;
            }
        }

        if ($allResolved) {
            $summary = $this->saveResolvedItemsAndGetSummary($sessionItems, $chatId, $employee, $log, $parsed, $text);
            $this->telegram->sendMessage($chatId, $summary);
            return;
        }

        // Buat session
        $sessionId = 'user_' . $chatId;
        $session = [
            'session_id' => $sessionId,
            'telegram_user_id' => (int)$chatId,
            'chat_id' => $chatId,
            'employee_id' => $employee->id,
            'raw_text' => $parsed['raw_text'],
            'items' => $sessionItems,
            'total_items' => count($sessionItems),
            'current_item_index' => $firstPendingIndex ?? 0,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'waiting_user',
            'completed_items' => count(array_filter($sessionItems, fn($si) => $si['status'] === 'resolved')),
        ];

        \Illuminate\Support\Facades\Cache::put(
            \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId,
            $session,
            \App\Services\AI\ClarificationSessionManager::CACHE_TTL
        );

        $this->sendNextClarificationItem($session, $chatId);
    }

    /**
     * Simpan rangkuman yang semua item sudah resolved
     */
    protected function processNormalReportForRangkuman(array $parsed, string $rawText, string $chatId, Employee $employee, TelegramBotLog $log, ?array $aiResult, array $itemsOpsi): void
    {
        $reportIds = [];
        $dateStr = $parsed['date']->format('Ymd');

        DB::beginTransaction();
        try {
            foreach ($itemsOpsi as $io) {
                $origItem = $parsed['items'][$io['index']] ?? null;

                // Generate ID
                $lastSeq = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
                    ->orderBy('telegram_report_id', 'desc')
                    ->value('telegram_report_id');
                $nextSeq = 1;
                if ($lastSeq && preg_match('/-(\d{3})$/', $lastSeq, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

                $report = MaintenanceReport::create([
                    'asset_id' => $io['resolved'] ? $io['asset_id'] : null,
                    'employee_id' => $employee->id,
                    'raw_text' => $rawText,
                    'action_taken' => $origItem['action'] ?? $io['action'],
                    'status' => $origItem['status'] ?? 'done',
                    'report_date' => $parsed['date'],
                    'shift' => $parsed['shift'],
                    'source' => 'telegram',
                    'telegram_report_id' => $telegramReportId,
                    'ai_confidence' => $aiResult['items'][$io['index']]['confidence'] ?? null,
                    'ai_suggested' => $io['resolved'],
                ]);
                $reportIds[] = $report;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $log->update(['maintenance_report_id' => $reportIds[0]->id ?? null]);

        $response = "  <b>Rangkuman tersimpan!</b>\n\n";
        $response .= "Shift: {$parsed['shift']} | {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Teknisi: {$employee->name}\n\n";

        foreach ($reportIds as $report) {
            $assetName = $report->asset ? $report->asset->tech_ident_no : '  (tidak dikenal)';
            $response .= "  <b>{$report->telegram_report_id}</b>\n";
            $response .= "   └ {$report->action_taken} → {$assetName}\n\n";
        }

        $this->telegram->sendMessage($chatId, $response);

        // Kirim daftar ID untuk foto
        $copyText = "  <b>Kirim foto  salin ID di bawah:</b>\n\n";
        foreach ($reportIds as $report) {
            $copyText .= "<code>{$report->telegram_report_id}</code>\n";
        }
        $copyText .= "\n <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $copyText .= "Atau kirim foto dengan caption ID di atas  ";
        $this->telegram->sendMessage($chatId, $copyText);
    }
        protected function processClarificationReply(string $text, string $chatId, Employee $employee, TelegramBotLog $log, array $activeSession): void
    {
        $sessionId = $activeSession['session_id'];
        $text = trim($text);
        $textLower = strtolower($text);

        $session = \App\Services\AI\ClarificationSessionManager::getSession($sessionId);
        if (!$session) {
            $this->telegram->sendMessage($chatId, "Sesi telah kadaluarsa. Silakan kirim laporan ulang.");
            return;
        }

        $items = &$session['items'];
        $totalItems = $session['total_items'] ?? 0;

        // Cari item pending yang sedang aktif
        $targetIdx = null;
        for ($i = $session['current_item_index'] ?? 0; $i < $totalItems; $i++) {
            if (($items[$i]['status'] ?? '') === 'pending') {
                $targetIdx = $i;
                break;
            }
        }

        if ($targetIdx === null) {
            // Semua item selesai  panggil finalize yang akan kirim summary dengan ID reports
            $this->finalizeSession($sessionId, $session, $chatId, $employee, $log);
            return;
        }

        $currentItem = &$items[$targetIdx];
        $possibleAssets = $currentItem['possible_assets'] ?? [];
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        // ===== CEK: JAWABAN DURASI =====
        if ($currentItem['awaiting_duration'] ?? false) {
            $parsedDuration = \App\Services\AI\ClarificationSessionManager::parseDurationInput($textLower);
            if ($parsedDuration !== null) {
                $currentItem['duration_hours'] = $parsedDuration;
                $currentItem['awaiting_duration'] = false;
                $currentItem['notes'] = ($currentItem['notes'] ?? '') . ' | Durasi: ' . $parsedDuration . ' jam';

                $session['updated_at'] = now()->toIso8601String();
                \Illuminate\Support\Facades\Cache::put(
                    \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                    \App\Services\AI\ClarificationSessionManager::CACHE_TTL
                );

                $this->sendNextClarificationItem($session, $chatId);
                return;
            }

            // Format durasi tidak valid  tanya lagi
            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );
            $this->telegram->sendMessage($chatId,
                "Format durasi tidak dikenali. Contoh: 2 (jam), 1.5, 30 (menit), atau 0 untuk lewati."
            );
            return;
        }

        // CEK: LEWATI
        if (in_array($textLower, ['0', 'lewati', 'skip', 'none', 'tidak ada'])) {
            $currentItem['status'] = 'skipped';
            $currentItem['work_type'] = 'skipped';
            $currentItem['notes'] = 'User memilih lewati';

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // CEK: ISI MANUAL (diawali # atau teks bebas > 3 kata yang bukan perintah)
        $isManualInput = str_starts_with($text, '#')
            || (str_word_count($text) >= 3
                && !in_array($textLower, ['0', 'lewati', 'skip', 'none', 'tidak ada', 'area', 'lokasi', 'pekerjaan area', 'baru', 'new', 'equipment baru'])
                && !preg_match('/^[A-H]$/', $text)
                && !is_numeric($text));

        if ($isManualInput) {
            $manualText = ltrim($text, '# ');
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'manual';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'MANUAL';
            $currentItem['resolved_asset_description'] = $manualText;
            $currentItem['notes'] = 'User input manual: ' . $manualText;

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->telegram->sendMessage($chatId,
                "Item " . ($targetIdx + 1) . "/" . $totalItems . " dicatat manual:\n" . $manualText
            );

            // Cek durasi
            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // CEK: PILIH DARI OPSI (letter A, B, C...)
        $selectedLetter = strtoupper($text);
        $selectedAsset = null;

        // Tentukan jumlah opsi equipment
        $totalAssetOptions = count($possibleAssets);
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        // Huruf untuk "Area" dan "Baru" tergantung jumlah opsi
        $areaLetter = $letters[$totalAssetOptions] ?? 'Z';
        $baruLetter = $letters[$totalAssetOptions + 1] ?? 'Y';

        // ===== CEK: AREA =====
        // User bisa memilih Area dengan:
        // 1. Huruf yang sesuai (misal: jika 1 opsi equipment, Area = B)
        // 2. Kata "area" langsung (area, lokasi)
        // 3. Angka untuk opsi setelah equipment
        if ($selectedLetter === $areaLetter || in_array($textLower, ['area', 'lokasi', 'pekerjaan area'])) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'area';
            $currentItem['notes'] = 'User mengkonfirmasi ini pekerjaan area';
            $currentItem['resolved_asset_id'] = null;
            $currentItem['resolved_asset_code'] = 'AREA';
            $currentItem['resolved_asset_description'] = 'Pekerjaan Area';

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->telegram->sendMessage($chatId,
                "Item " . ($targetIdx + 1) . "/" . $totalItems . " dicatat sebagai PEKERJAAN AREA"
            );

            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // ===== CEK: EQUIPMENT BARU =====
        // Jika tidak ada opsi equipment: B = Equipment Baru
        // Jika ada opsi: huruf setelah Area = Equipment Baru
        if ($selectedLetter === $baruLetter ||
            ($totalAssetOptions === 0 && $selectedLetter === 'B') ||
            in_array($textLower, ['baru', 'new', 'equipment baru'])) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'new_equipment';
            $currentItem['notes'] = 'User mengkonfirmasi ini equipment baru, perlu didaftarkan admin';
            $currentItem['resolved_asset_code'] = 'NEW';
            $currentItem['resolved_asset_description'] = 'Equipment Baru';

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->telegram->sendMessage($chatId,
                "Item " . ($targetIdx + 1) . "/" . $totalItems . " dicatat sebagai EQUIPMENT BARU (perlu didaftarkan admin)"
            );

            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // Cek huruf A-H untuk opsi equipment
        if (preg_match('/^[A-H]$/', $selectedLetter)) {
            $assetIndex = array_search($selectedLetter, $letters);
            if ($assetIndex !== false && isset($possibleAssets[$assetIndex])) {
                $selectedAsset = $possibleAssets[$assetIndex];
            }
        }

        // Cek angka 1-8 untuk opsi equipment (alternatif)
        if (is_numeric($text) && !$selectedAsset) {
            $numIdx = (int)$text - 1;
            if (isset($possibleAssets[$numIdx])) {
                $selectedAsset = $possibleAssets[$numIdx];
            }
        }

        if ($selectedAsset) {
            $currentItem['status'] = 'resolved';
            $currentItem['work_type'] = 'equipment';
            $currentItem['resolved_asset_id'] = $selectedAsset['id'];
            $currentItem['resolved_asset_code'] = $selectedAsset['tech_ident_no'] ?? '';
            $currentItem['resolved_asset_description'] = $selectedAsset['description'] ?? '';
            $currentItem['resolved_location'] = $selectedAsset['location'] ?? '';
            $currentItem['notes'] = 'Dipilih user: ' . $selectedLetter;

            // Learn alias
            \App\Services\AI\ClarificationSessionManager::learnAlias(
                $currentItem['raw_text'], $selectedAsset, $session['employee_id']
            );

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->telegram->sendMessage($chatId,
                "Item " . ($targetIdx + 1) . "/" . $totalItems . " disimpan: " . $selectedAsset['tech_ident_no']
            );

            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // GAGAL MENGENALI INPUT
        $currentItem['attempts'] = ($currentItem['attempts'] ?? 0) + 1;

        if ($currentItem['attempts'] >= $currentItem['max_attempts']) {
            $currentItem['status'] = 'unidentified';
            $currentItem['work_type'] = 'unknown';
            $currentItem['notes'] = 'Max attempts exceeded';

            $session['updated_at'] = now()->toIso8601String();
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
                \App\Services\AI\ClarificationSessionManager::CACHE_TTL
            );

            $this->telegram->sendMessage($chatId, "Percobaan habis, lanjut ke item berikutnya.");
            $this->sendNextClarificationItem($session, $chatId);
            return;
        }

        // Masih ada sisa percobaan
        $session['updated_at'] = now()->toIso8601String();
        \Illuminate\Support\Facades\Cache::put(
            \App\Services\AI\ClarificationSessionManager::CACHE_PREFIX . $sessionId, $session,
            \App\Services\AI\ClarificationSessionManager::CACHE_TTL
        );

        $attemptsLeft = $currentItem['max_attempts'] - $currentItem['attempts'];
        $this->telegram->sendMessage($chatId,
            "Pilihan tidak dikenali. Sisa percobaan: " . $attemptsLeft . "x.\n\n" .
            "Ketik huruf (A, B, C...), <b>Area</b>, <b>0</b>, atau <b>#teks manual</b>."
        );
    }
    /**
     * Finalisasi session  simpan semua report, kirim summary
     */
        protected function finalizeSession(string $sessionId, array $session, string $chatId, Employee $employee,
TelegramBotLog $log): void
    {
        // Simpan semua report
        $savedReports = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);

        if (!empty($savedReports)) {
            $log->update(['maintenance_report_id' => $savedReports[0]['id']]);
        }

        // Build summary dengan ID report
        $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
        $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee, $savedReports);
        $this->telegram->sendMessage($chatId, $summaryMsg);

        // Hapus session
        \App\Services\AI\ClarificationSessionManager::destroySession($sessionId);
    }

    /**
     * Proses laporan normal (tanpa klarifikasi atau fallback)
     */
    protected function processNormalReport(array $parsed, string $rawText, string $chatId, Employee $employee, TelegramBotLog $log, ?array $aiResult = null): void
    {
        // Proses setiap item laporan
        $reportIds = [];
        $dateStr = $parsed['date']->format('Ymd');

        // Hitung sequence
        $lastSeq = null;
        $maxAttempts = 0;
        while ($maxAttempts < 5) {
            $lastSeq = MaintenanceReport::where('telegram_report_id', 'LIKE', "LMS-{$dateStr}-%")
                ->sharedLock()
                ->orderBy('telegram_report_id', 'desc')
                ->value('telegram_report_id');

            $nextSeq = 1;
            if ($lastSeq && preg_match('/-(\d{3})$/', $lastSeq, $m)) {
                $nextSeq = (int)$m[1] + 1;
            }

            $testId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
            $exists = MaintenanceReport::where('telegram_report_id', $testId)->exists();
            if (!$exists) break;
            $maxAttempts++;
        }

        DB::beginTransaction();
        try {
            foreach ($parsed['items'] as $index => $item) {
                $asset = null;
                $aiConfidence = null;
                $aiSuggested = false;
                $needsReview = false;
                $aiProvider = null;
                $itemAiNotes = null;

                if ($aiResult && isset($aiResult['items'][$index])) {
                    $aiItem = $aiResult['items'][$index];
                    $aiProvider = $aiResult['provider_used'] ?? null;
                    $itemAiNotes = $aiItem['notes'] ?? ($aiResult['ai_notes'] ?? null);

                    if ($aiItem['suggested_asset_id'] && $aiItem['confidence'] >= 0.8) {
                        $asset = \App\Models\Asset::find($aiItem['suggested_asset_id']);
                        $aiConfidence = $aiItem['confidence'];
                        $aiSuggested = true;

                        if (isset($aiResult['new_aliases'])) {
                            foreach ($aiResult['new_aliases'] as $alias) {
                                try {
                                    \App\Models\AssetAlias::updateOrCreate(
                                        [
                                            'asset_id' => $alias['asset_id'],
                                            'alias' => $alias['text'],
                                            'employee_id' => $employee->id,
                                        ],
                                        [
                                            'confidence_score' => $alias['confidence'] * 100,
                                            'auto_generated' => true,
                                        ]
                                    );
                                } catch (\Throwable $e) {}
                            }
                        }
                    } else {
                        $asset = $this->parser->matchEquipment($item['action']);
                        if ($aiItem['suggested_asset_id']) {
                            $aiSuggested = true;
                            $aiConfidence = $aiItem['confidence'];
                            $needsReview = true;
                        }
                    }
                } else {
                    $asset = $this->parser->matchEquipment($item['action']);
                }

                $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
                $nextSeq++;

                $attempts = 0;
                while (MaintenanceReport::where('telegram_report_id', $telegramReportId)->exists() && $attempts < 10) {
                    $nextSeq++;
                    $telegramReportId = 'LMS-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
                    $attempts++;
                }

                $report = MaintenanceReport::create([
                    'asset_id' => $asset ? $asset->id : null,
                    'employee_id' => $employee->id,
                    'raw_text' => $parsed['raw_text'],
                    'action_taken' => $item['action'],
                    'status' => $item['status'],
                    'report_date' => $parsed['date'],
                    'shift' => $parsed['shift'],
                    'source' => 'telegram',
                    'telegram_report_id' => $telegramReportId,
                    'ai_confidence' => $aiConfidence,
                    'ai_suggested' => $aiSuggested,
                    'needs_admin_review' => $needsReview,
                    'ai_provider_used' => $aiProvider,
                    'ai_notes' => $itemAiNotes,
                    'ai_fallback_reason' => $aiResult['fallback_reason'] ?? null,
                ]);

                $reportIds[] = $report;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (!empty($reportIds)) {
            $log->update(['maintenance_report_id' => $reportIds[0]->id]);
        }

        // Kirim PESAN UTAMA
        $response = "  <b>Laporan berhasil disimpan!</b>\n\n";
        $response .= "Shift: {$parsed['shift']}\n";
        $response .= "Tanggal: {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Teknisi: {$employee->name} ({$employee->department})\n\n";

        foreach ($reportIds as $i => $report) {
            $statusEmoji = match($report->status) {
                'done' => ' DONE',
                'continue' => 'CONTINUE',
                'pending' => 'PENDING',
                default => 'DEFAULT',
            };

            $assetName = $report->asset ? $report->asset->tech_ident_no : '  (tidak dikenal)';
            $suffix = '';
            if ($report->ai_suggested) {
                $pct = round(($report->ai_confidence ?? 0) * 100);
                $suffix = "  {$pct}%";
            }
            $response .= "{$statusEmoji} <b>{$report->telegram_report_id}</b>\n";
            $response .= "   └ {$report->action_taken}\n";
            $response .= "   └ Alat: {$assetName}{$suffix} [{$report->status}]\n\n";
        }

        $unknownCount = collect($reportIds)->filter(fn($r) => !$r->asset_id)->count();
        if ($unknownCount > 0) {
            $response .= "\n  <b>{$unknownCount} alat tidak dikenal.</b> Silakan mapping manual di panel.";
        }

        $this->telegram->sendMessage($chatId, $response);

        // Kirim PESAN KEDUA  daftar ID
        $copyText = "  <b>Kirim foto  salin ID di bawah:</b>\n\n";
        foreach ($reportIds as $report) {
            $copyText .= "<code>{$report->telegram_report_id}</code>\n";
        }
        $copyText .= "\n <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $copyText .= "Atau kirim foto dengan caption ID di atas  ";

        $this->telegram->sendMessage($chatId, $copyText);
    }

    /**
     * Handle foto dari user  hanya attach ke ID yang disebut di caption atau reply
     */
    protected function handlePhotoMessage(array $message, string $chatId, ?Employee $employee, TelegramBotLog $log): void
    {
        $replyTo = $message['reply_to_message'] ?? null;
        $caption = trim($message['caption'] ?? '');

        // CEK: Jika ada caption dan TIDAK ada ID LMS -> REPORT HARIAN
        if (!empty($caption) && !preg_match('/LMS-\d{8}-\d{3}/', $caption)) {
            if ($employee) {
                $this->handleReportHarianFromPhoto($message, $caption, $chatId, $employee, $log);
            } else {
                $this->telegram->sendMessage($chatId, "Anda belum terdaftar. Ketik /register.");
            }
            return;
        }

// ... sisanya tetap (original handlePhotoMessage)

        // PRIORITAS 1: ID dari CAPTION foto (yang paling spesifik)
        $reportIds = [];
        if (!empty($caption)) {
            preg_match_all('/LMS-\d{8}-\d{3}/', $caption, $captionMatches);
            $reportIds = $captionMatches[0] ?? [];
        }

        // PRIORITAS 2: Jika tidak ada ID di caption, ambil dari reply (hanya 1 ID pertama yang cocok)
        if (empty($reportIds) && $replyTo) {
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';
            // Cari ID yang sepertinya baru  ambil yang prefix-nya cocok dengan hari ini
            preg_match_all('/LMS-\d{8}-\d{3}/', $replyText, $replyMatches);
            $allIds = $replyMatches[0] ?? [];

            if (!empty($allIds)) {
                // Ambil hanya 1 ID terakhir/terbawah (yang paling baru)
                $reportIds = [end($allIds)];
            }
        }

        if (empty($reportIds)) {
            $this->telegram->sendMessage($chatId,
                "  <b>ID laporan tidak ditemukan.</b>\n\n" .
                "Cara kirim foto:\n" .
                "1. Copy ID laporan (contoh: <code>LMS-20260518-001</code>)\n" .
                "2. Kirim foto dengan caption ID tersebut\n\n" .
                "Atau reply pesan bot yang berisi daftar ID + attach foto."
            );
            return;
        }

        // Download foto SEKALI
        $photoPath = $this->photoHandler->handlePhoto($message, $chatId);

        if (!$photoPath) {
            $this->telegram->sendMessage($chatId, "  Gagal mengunduh foto. Silakan coba lagi.");
            return;
        }

        $attachedTo = [];

        foreach ($reportIds as $rid) {
            $report = MaintenanceReport::where('telegram_report_id', $rid)->first();

            if (!$report) {
                $attachedTo[] = "  {$rid} tidak ditemukan";
                continue;
            }

            // Validasi: hanya teknisi yang bersangkutan
            if ($employee && $report->employee_id !== $employee->id) {
                $attachedTo[] = "  {$rid} bukan laporan Anda";
                continue;
            }

            // Cek apakah foto ini sudah pernah di-attach (hindari duplikasi)
            $existingDocs = json_decode($report->documents ?? '[]', true);
            if (in_array($photoPath, $existingDocs)) {
                $attachedTo[] = "  {$rid}  foto sudah ada";
                continue;
            }

            // Attach foto ke laporan ini
            $this->photoHandler->attachPhotoToReport($report, $photoPath);
            $docCount = count(json_decode($report->fresh()->documents ?? '[]', true));
            $attachedTo[] = "  {$rid} ({$docCount} file)";
        }

        $this->telegram->sendMessage($chatId,
            "  <b>Foto tersimpan!</b>\n\n" .
            implode("\n", $attachedTo)
        );

        // Update log  simpan ID pertama yang berhasil
        $firstSuccessReportId = null;
        $firstSuccessNumericId = null;
        foreach ($reportIds as $rid) {
            $report = MaintenanceReport::where('telegram_report_id', $rid)->first();
            if ($report) {
                $firstSuccessReportId = $report->telegram_report_id;
                $firstSuccessNumericId = $report->id;
                break;
            }
        }

        $log->update([
            'maintenance_report_id' => $firstSuccessNumericId,
            'incoming_message' => "  Foto untuk " . implode(', ', $reportIds),
        ]);
    }

    /**
     * Proses registrasi via nomor HP
     */
    protected function processRegistration(string $phoneNumber, string $chatId, TelegramBotLog $log): void
    {
        // Normalisasi nomor
        $phone = $phoneNumber;
        if (substr($phone, 0, 1) == '0') {
            $phone = '62' . substr($phone, 1);
        }

        $employee = Employee::where('phone_number', $phone)->first();

        if (!$employee) {
            $this->telegram->sendMessage($chatId,
                "  <b>Nomor tidak terdaftar!</b>\n\n" .
                "Nomor <code>{$phone}</code> tidak ditemukan di database.\n\n" .
                "Silakan hubungi admin untuk mendaftarkan nomor Anda terlebih dahulu."
            );
            return;
        }

        // Cek apakah sudah terdaftar di chat lain
        if ($employee->telegram_id && $employee->telegram_id !== $chatId) {
            $autoApprove = TelegramSetting::getValue('auto_approve', 'false');

            if ($autoApprove === 'true') {
                // Auto-approve: pindahkan ke chat baru
                $employee->update(['telegram_id' => $chatId]);
                $this->telegram->sendMessage($chatId,
                    "  <b>Akun dipindahkan ke chat ini.</b>\n" .
                    "Selamat datang kembali, {$employee->name}!"
                );
                return;
            } else {
                $this->telegram->sendMessage($chatId,
                    "  <b>Akun ini sudah terdaftar di chat lain.</b>\n\n" .
                    "Hubungi admin untuk memverifikasi ulang."
                );
                return;
            }
        }

        // Daftarkan
        $employee->update(['telegram_id' => $chatId]);

        $this->telegram->sendMessage($chatId,
            "  <b>Pendaftaran berhasil!</b>\n\n" .
            "Nama: {$employee->name}\n" .
            "NIK: {$employee->nik}\n" .
            "Departemen: {$employee->department}\n" .
            "Shift: {$employee->shift}\n\n" .
            "Sekarang Anda bisa langsung mengirim laporan dengan format:\n" .
            "<code>Report shift 1\n" .
            "DD/MM/YYYY\n" .
            "1. Aksi perbaikan - done</code>\n\n" .
            "Ketik /help untuk panduan lengkap."
        );
    }

    /**
     * Handle command /status LMS-YYYYMMDD-NNN
     */
    protected function handleStatusCommand(string $args, string $chatId, TelegramBotLog $log): void
    {
        $args = trim($args);

        if (empty($args)) {
            $this->telegram->sendMessage($chatId,
                "  <b>Cek Status Laporan</b>\n\n" .
                "Gunakan: <code>/status LMS-20260517-001</code>\n\n" .
                "Contoh: /status LMS-20260517-001"
            );
            return;
        }

        $report = MaintenanceReport::with(['asset', 'employee'])
            ->where('telegram_report_id', $args)
            ->orWhere('id', $args)
            ->first();

        if (!$report) {
            $this->telegram->sendMessage($chatId,
                "  Laporan dengan ID <b>{$args}</b> tidak ditemukan."
            );
            return;
        }

        $statusEmoji = match($report->status) {
            'done' => ' ',
            'continue' => ' ',
            'pending' => ' ',
            default => ' ',
        };

        $response = "  <b>Detail Laporan</b>\n\n";
        $response .= "ID: {$report->telegram_report_id}\n";
        $response .= "Tanggal: {$report->report_date->format('d/m/Y')}\n";
        $response .= "Shift: {$report->shift}\n";
        $response .= "Alat: " . ($report->asset->tech_ident_no ?? 'N/A') . "\n";
        $response .= "Teknisi: " . ($report->employee->name ?? 'N/A') . "\n";
        $response .= "Tindakan: {$report->action_taken}\n";
        $response .= "Status: {$statusEmoji} {$report->status}\n";
        $response .= "Sumber: {$report->source}\n";

        $docs = $report->documents ? json_decode($report->documents, true) : [];
        $response .= "Dokumen: " . count($docs) . " file\n";

        $response .= "\nDibuat: {$report->created_at->format('d/m/Y H:i')}";

        $this->telegram->sendMessage($chatId, $response);
    }

    /**
     * Kirim pesan selamat datang
     */
    protected function sendWelcome(string $chatId): void
    {
        $this->telegram->sendMessage($chatId,
            "  <b>Selamat datang di Bot Laporan Maintenance!</b>\n\n" .
            "Bot ini digunakan untuk mencatat laporan perbaikan harian.\n\n" .
            "  <b>Format laporan:</b>\n" .
            "<code>Report shift 1\n" .
            "17/05/2026\n" .
            "1. Aksi perbaikan - done\n" .
            "2. Aksi lain (continue)</code>\n\n" .
            "  <b>Kirim foto:</b>\n" .
            "Reply pesan bot dengan ID laporan + foto.\n\n" .
            "🔹 /register  Daftarkan akun Anda\n" .
            "🔹 /status [ID]  Cek status laporan\n" .
            "🔹 /me  Lihat profil Anda\n" .
            "🔹 /help  Bantuan lengkap\n\n" .
            "📞 Hubungi admin jika ada kendala."
        );
    }

    /**
     * Kirim bantuan lengkap
     */
    protected function sendHelp(string $chatId): void
    {
        $this->telegram->sendMessage($chatId,
            "  <b>Panduan Penggunaan Bot</b>\n\n" .
            "<b>1. Daftar Akun</b>\n" .
            "Ketik /register, lalu kirim nomor HP yang terdaftar.\n\n" .
            "<b>2. Laporan Harian</b>\n" .
            "Format:\n" .
            "<code>Report shift [1/2/3/reguler]\n" .
            "DD/MM/YYYY\n" .
            "1. Aksi - done\n" .
            "2. Aksi (continue)\n" .
            "3. Aksi1 (done) & Aksi2 (pending)</code>\n\n" .
            "<b>Status yang didukung:</b> done, continue, pending\n\n" .
            "<b>3. Kirim Foto</b>\n" .
            "Reply pesan bot dengan ID laporan + attach foto.\n\n" .
            "<b>4. Cek Status</b>\n" .
            "/status LMS-20260517-001\n\n" .
            "<b>5. Lihat Profil</b>\n" .
            "/me\n\n" .
            "Ada masalah? Hubungi admin."
        );
    }

    /**
     * Deteksi tipe pesan dari update Telegram
     */
    protected function detectMessageType(array $message): string
    {
        if (isset($message['photo'])) return 'photo';
        if (isset($message['text'])) {
            if (str_starts_with($message['text'], '/')) return 'command';
            return 'text';
        }
        if (isset($message['contact'])) return 'contact';
        return 'unknown';
    }
}
