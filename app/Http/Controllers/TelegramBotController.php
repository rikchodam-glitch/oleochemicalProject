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
     * Webhook endpoint — menerima semua update dari Telegram
     */
    public function webhook(Request $request)
    {
        $update = $request->all();
        $message = $update['message'] ?? null;
        $callbackQuery = $update['callback_query'] ?? null;
        $updateId = $update['update_id'] ?? null;

        // 🔥 HANDLE CALLBACK QUERY (tombol inline ditekan)
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

        // 🔥 CEK DUPLIKASI VIA UPDATE_ID — paling akurat
        if ($updateId) {
            $alreadyProcessed = TelegramBotLog::where('telegram_update_id', $updateId)->exists();
            if ($alreadyProcessed) {
                Log::info("Duplicate update_id skipped: {$updateId}");
                return response()->json(['status' => 'duplicate_skipped']);
            }
        }

        // CEK BLACKLIST
        if (TelegramBlacklist::where('telegram_chat_id', $chatId)->exists()) {
            $this->telegram->sendMessage($chatId, "⛔ Anda telah diblokir dari bot ini. Hubungi admin untuk informasi lebih lanjut.");
            return response()->json(['status' => 'blocked']);
        }

        // CEK STATUS BOT
        $botStatus = TelegramSetting::getValue('bot_status', 'active');
        if ($botStatus === 'maintenance') {
            $this->telegram->sendMessage($chatId, "🔧 Bot sedang dalam perawatan. Silakan coba lagi nanti.");
            return response()->json(['status' => 'maintenance']);
        }

        // IDENTIFIKASI USER
        $employee = Employee::where('telegram_id', $chatId)->first();

        // LOG SEMUA PESAN MASUK
        $incomingMsg = $text ?? '(photo)';
        if (isset($message['photo'])) {
            $fileId = $message['photo'][count($message['photo'])-1]['file_id'] ?? '';
            $incomingMsg = "📸 [{$fileId}] " . ($text ?? '');
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
                "❌ Maaf, terjadi kesalahan internal. Silakan coba lagi.\n\n" .
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
     * Handle callback query — user menekan tombol inline keyboard
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
                        "❌ " . ($result['error'] ?? 'Terjadi kesalahan.')
                    );
                    return response()->json(['status' => 'error']);
                }

                $session = $result['session'];

                // ✅ ITEM RESOLVED
                if ($result['resolved']) {
                    $asset = $result['asset'];
                    $confirmText = "✅ <b>Item " . ($result['item_index'] + 1) . " dikonfirmasi!</b>\n\n" .
                        "Equipment: <b>{$asset['tech_ident_no']}</b>\n" .
                        "Deskripsi: {$asset['description']}\n";
                    if (!empty($asset['location'])) {
                        $confirmText .= "Lokasi: {$asset['location']}\n";
                    }
                    $this->telegram->editMessageText($chatId, $messageId, $confirmText);
                }

                // ⏭️ ITEM SKIPPED
                if (!empty($result['skipped'])) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        "⏭️ <b>Item " . ($result['item_index'] + 1) . " dilewati.</b>"
                    );
                }

                // ⚠️ ITEM UNIDENTIFIED
                if (!empty($result['unidentified'])) {
                    $this->telegram->editMessageText($chatId, $messageId,
                        "⚠️ <b>Item " . ($result['item_index'] + 1) . " tidak teridentifikasi.</b>\n" .
                        "Setelah " . $result['attempts'] . " kali percobaan."
                    );
                }

                // ✅ SEMUA SELESAI
                if ($result['completed']) {
                    $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
                    $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee);

                    $savedIds = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);
                    if (!empty($savedIds)) {
                        TelegramBotLog::where('telegram_chat_id', $chatId)
                            ->latest()
                            ->first()
                            ?->update(['maintenance_report_id' => $savedIds[0]]);
                    }

                    $this->telegram->sendMessage($chatId, $summaryMsg);
                    \App\Services\AI\ClarificationSessionManager::destroySession($sessionId);
                    return response()->json(['status' => 'completed']);
                }

                // ➡️ LANJUT KE ITEM BERIKUTNYA
                $nextIdx = $result['next_item_index'];
                $remaining = $result['remaining_items'];
                $total = $result['total_items'];

                $nextItem = \App\Services\AI\ClarificationSessionManager::getCurrentItem($session);
                $nextMsg = \App\Services\AI\ClarificationSessionManager::buildClarificationMessage($session, $nextItem);

                $nextMsg = "➡️ <b>Lanjut Item " . ($nextIdx + 1) . " dari {$total}</b>\n\n" . $nextMsg;

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
                        "❌ " . ($result['error'] ?? 'Terjadi kesalahan.')
                    );
                    return response()->json(['status' => 'error']);
                }

                $session = $result['session'];

                $this->telegram->editMessageText($chatId, $messageId,
                    "⏭️ <b>Item " . ($result['item_index'] + 1) . " dilewati.</b>"
                );

                if ($result['completed']) {
                    $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
                    $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee);

                    $savedIds = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);
                    if (!empty($savedIds)) {
                        TelegramBotLog::where('telegram_chat_id', $chatId)
                            ->latest()
                            ->first()
                            ?->update(['maintenance_report_id' => $savedIds[0]]);
                    }

                    $this->telegram->sendMessage($chatId, $summaryMsg);
                    \App\Services\AI\ClarificationSessionManager::destroySession($sessionId);
                    return response()->json(['status' => 'completed']);
                }

                $nextItem = \App\Services\AI\ClarificationSessionManager::getCurrentItem($session);
                $total = $result['total_items'];
                $nextIdx = $result['next_item_index'];

                $nextMsg = \App\Services\AI\ClarificationSessionManager::buildClarificationMessage($session, $nextItem);
                $nextMsg = "➡️ <b>Lanjut Item " . ($nextIdx + 1) . " dari {$total}</b>\n\n" . $nextMsg;

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
                        "Status: ✅ Terdaftar"
                    );
                } else {
                    $this->telegram->sendMessage($chatId, "❌ Anda belum terdaftar. Ketik /register untuk mendaftar.");
                }
                break;

            default:
                $this->telegram->sendMessage($chatId,
                    "❌ Perintah tidak dikenal. Ketik /help untuk bantuan."
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
            "⚠️ <b>Anda belum terdaftar!</b>\n\n" .
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
            "📝 <b>Pendaftaran Teknisi</b>\n\n" .
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
        $activeSession = \App\Services\AI\ClarificationSessionManager::getActiveSession((int)$chatId);
        if ($activeSession && $activeSession['status'] === 'waiting_user') {
            $this->processClarificationReply($text, $chatId, $employee, $log, $activeSession);
            return;
        }

        // DETEKSI JENIS: Report Rangkuman atau Harian?
        // Rangkuman: dimulai "Report shift" atau ada format "Report shift X\nDD/MM/YYYY"
        // Harian: teks biasa (1 equipment)
        $isRangkuman = preg_match('/^Report\s+shift\s+\d+/i', trim($text));

        if ($isRangkuman) {
            $this->handleReportRangkuman($text, $chatId, $employee, $log);
        } else {
            $this->handleReportHarian($text, $chatId, $employee, $log);
        }
    }

    /**
     * Report Harian — 1 equipment, shift/tanggal auto dari jam kirim
     */
    protected function handleReportHarian(string $text, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        $now = now();

        // Tentukan shift berdasarkan jam
        $hour = (int)$now->format('H');
        $shift = match(true) {
            $hour >= 8 && $hour < 16 => '1',
            $hour >= 16 || $hour < 0 => '2',
            default => '3', // malam
        };
        // Kalau jam 00-08, shift malam, tanggalnya mundur 1 hari? Atau tetap hari ini
        if ($hour >= 0 && $hour < 8) {
            $shift = 'Malam';
        }

        $date = $now;

        $this->telegram->sendMessage($chatId,
            "📋 <b>Laporan Harian</b>\n" .
            "Shift: {$shift} | Tanggal: {$date->format('d/m/Y')}\n" .
            "🧠 Menganalisa..."
        );

        // Parse teks sebagai 1 item
        $parsed = [
            'date' => $date,
            'shift' => $shift,
            'items' => [
                [
                    'action' => trim($text),
                    'status' => 'done',
                ]
            ],
            'raw_text' => $text,
        ];

        // AI mapping untuk 1 item
        $gateway = new AiGatewayService();
        $gateway->withContext($employee);

        try {
            $singleText = "Item 1: " . $text . " (done)";
            $aiResult = $gateway->analyzeWithClarification($singleText, $employee);
        } catch (\Throwable $e) {
            Log::warning("AI Harian gagal: {$e->getMessage()}");
            $aiResult = null;
        }

        $resolvedAsset = null;

        if ($aiResult && isset($aiResult['items']) && !empty($aiResult['items'])) {
            $aiItem = $aiResult['items'][0];
            $conf = $aiItem['confidence'] ?? 0;

            if ($conf >= 0.8 && !empty($aiItem['suggested_asset_id'])) {
                $resolvedAsset = \App\Models\Asset::find($aiItem['suggested_asset_id']);
            }
        }

        // Jika ada asset, langsung simpan
        if ($resolvedAsset) {
            $this->saveSingleReport($parsed, $resolvedAsset, $aiResult, $chatId, $employee, $log);
        } else {
            // Ambigu — tampilkan opsi
            $possibleAssets = $aiResult['items'][0]['possible_assets'] ?? [];

            if (!empty($possibleAssets)) {
                // Tampilkan opsi
                $msg = "🤖 <b>Analisa AI</b>\n\n";
                $msg .= "1. {$text}\n";
                $msg .= "   Pilih:\n";
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($possibleAssets as $i => $pa) {
                    $msg .= "   {$letters[$i]}. {$pa['tech_ident_no']} — {$pa['description']}";
                    if (!empty($pa['location'])) $msg .= " ({$pa['location']})";
                    $msg .= "\n";
                }
                $msg .= "\n📝 Balas: <code>1.A</code> untuk pilih opsi A\n";
                $msg .= "Atau: <code>lewati</code> untuk skip";

                // Buat session 1 item
                $session = \App\Services\AI\ClarificationSessionManager::createSession(
                    (int)$chatId, $chatId, $aiResult ?? [], $employee, $text,
                    $parsed['items'] ?? null,
                    [
                        [
                            'action' => $text,
                            'status' => 'done',
                            'possible_assets' => $possibleAssets,
                            'clarification_question' => 'Pilih equipment:',
                            'parsed_date' => $date,
                            'parsed_shift' => $shift,
                        ]
                    ]
                );
                $this->telegram->sendMessage($chatId, $msg);
            } else {
                // Tidak ada opsi — simpan tanpa asset
                $this->saveSingleReport($parsed, null, null, $chatId, $employee, $log);
            }
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

        $assetName = $asset ? $asset->tech_ident_no : '⚠️ (tidak dikenal)';
        $confText = $aiResult && isset($aiResult['items'][0]['confidence'])
            ? ' 🤖' . round($aiResult['items'][0]['confidence'] * 100) . '%'
            : '';

        // Kirim konfirmasi + ID
        $response = "✅ <b>Laporan direkam!</b>\n\n";
        $response .= "Shift: {$parsed['shift']} | {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Alat: {$assetName}{$confText}\n";
        $response .= "Aksi: {$parsed['items'][0]['action']}\n\n";
        $response .= "📸 <b>Kirim foto — salin ID di bawah:</b>\n\n";
        $response .= "<code>{$telegramReportId}</code>\n\n";
        $response .= "📌 <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $response .= "Atau kirim foto dengan caption ID di atas ⬆️";

        $this->telegram->sendMessage($chatId, $response);
    }

    /**
     * Report Rangkuman — format "Report shift X\nDD/MM/YYYY\n1. Aksi - done"
     * Multiple items, AI analisa batch
     */
    protected function handleReportRangkuman(string $text, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        // Parse teks laporan
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
            "📋 <b>Rangkuman</b> — " . count($parsed['items']) . " item ditemukan.\n" .
            "🧠 AI sedang menganalisa..."
        );

        // PANGGIL AI 1x dengan full teks
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

        $itemsOpsi = [];

        if ($aiResult && isset($aiResult['items']) && !empty($aiResult['items'])) {
            foreach ($aiResult['items'] as $index => $aiItem) {
                $origItem = $parsed['items'][$index] ?? null;
                $conf = $aiItem['confidence'] ?? 0;
                $possibleAssets = $aiItem['possible_assets'] ?? [];

                if ($conf >= 0.8 && !empty($aiItem['suggested_asset_id'])) {
                    $assetModel = \App\Models\Asset::find($aiItem['suggested_asset_id']);
                    $itemsOpsi[] = [
                        'index' => $index,
                        'action' => $origItem['action'] ?? '',
                        'status' => $origItem['status'] ?? 'done',
                        'resolved' => true,
                        'asset_id' => $aiItem['suggested_asset_id'],
                        'tech_ident_no' => $assetModel->tech_ident_no ?? $aiItem['suggested_tech_ident_no'] ?? '',
                        'description' => $assetModel->description ?? '',
                    ];
                } else {
                    $opsi = [];
                    $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
                    foreach ($possibleAssets as $paIdx => $pa) {
                        $opsi[] = [
                            'letter' => $letters[$paIdx] ?? '?',
                            'id' => $pa['id'],
                            'tech_ident_no' => $pa['tech_ident_no'] ?? '',
                            'description' => $pa['description'] ?? '',
                            'location' => $pa['location'] ?? '',
                        ];
                    }
                    $itemsOpsi[] = [
                        'index' => $index,
                        'action' => $origItem['action'] ?? $aiItem['original_text'] ?? '',
                        'status' => $origItem['status'] ?? 'done',
                        'resolved' => false,
                        'opsi' => $opsi,
                    ];
                }
            }
        } else {
            // AI gagal
            foreach ($parsed['items'] as $index => $item) {
                $itemsOpsi[] = [
                    'index' => $index,
                    'action' => $item['action'],
                    'status' => $item['status'] ?? 'done',
                    'resolved' => false,
                    'opsi' => [],
                ];
            }
        }

        // Build pesan
        $msg = "🤖 <b>Analisa Rangkuman</b>\n\n";
        $msg .= count($itemsOpsi) . " item:\n\n";

        foreach ($itemsOpsi as $io) {
            $num = $io['index'] + 1;
            $msg .= "<b>{$num}. {$io['action']}</b> [{$io['status']}]\n";
            if ($io['resolved']) {
                $msg .= "   ✅ {$io['tech_ident_no']} — {$io['description']}\n";
            } elseif (!empty($io['opsi'])) {
                $msg .= "   Pilih:\n";
                foreach ($io['opsi'] as $o) {
                    $msg .= "   {$o['letter']}. {$o['tech_ident_no']} — {$o['description']}";
                    if ($o['location']) $msg .= " ({$o['location']})";
                    $msg .= "\n";
                }
            } else {
                $msg .= "   ❓ Tidak ada opsi\n";
            }
            $msg .= "\n";
        }

        $ambiguousCount = count(array_filter($itemsOpsi, fn($io) => !$io['resolved']));
        if ($ambiguousCount > 0) {
            $msg .= "📝 <b>Cara menjawab:</b>\n";
            $msg .= "Balas dengan format nomor.huruf\n";
            $msg .= "Contoh: <code>1.A 2.C 3.B 4.A</code>\n";
            $msg .= "Atau: <code>1.A</code> jika hanya item 1 saja\n";
            $msg .= "Ketik <code>lewati</code> untuk skip semua\n\n";
            $msg .= "⏳ Sisa waktu: 5 menit";
        } else {
            $msg .= "\n✅ Semua item berhasil dikenali! Menyimpan laporan...";
            // Simpan langsung
            $this->processNormalReportForRangkuman($parsed, $text, $chatId, $employee, $log, $aiResult, $itemsOpsi);
            return;
        }

        // Simpan session
        $session = \App\Services\AI\ClarificationSessionManager::createSession(
            (int)$chatId, $chatId, $aiResult ?? [], $employee,
            $parsed['raw_text'], $parsed['items'] ?? null,
            array_map(function($io) {
                return [
                    'action' => $io['action'],
                    'status' => $io['status'] ?? 'done',
                    'possible_assets' => array_map(function($o) {
                        return [
                            'id' => $o['id'],
                            'tech_ident_no' => $o['tech_ident_no'],
                            'description' => $o['description'],
                            'location' => $o['location'] ?? '',
                        ];
                    }, $io['opsi'] ?? []),
                    'clarification_question' => 'Pilih equipment:',
                    'parsed_date' => null, // akan diisi dari session
                    'parsed_shift' => null,
                ];
            }, array_filter($itemsOpsi, fn($io) => !$io['resolved']))
        );

        $this->telegram->sendMessage($chatId, $msg);
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

        $response = "✅ <b>Rangkuman tersimpan!</b>\n\n";
        $response .= "Shift: {$parsed['shift']} | {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Teknisi: {$employee->name}\n\n";

        foreach ($reportIds as $report) {
            $assetName = $report->asset ? $report->asset->tech_ident_no : '⚠️ (tidak dikenal)';
            $response .= "✅ <b>{$report->telegram_report_id}</b>\n";
            $response .= "   └ {$report->action_taken} → {$assetName}\n\n";
        }

        $this->telegram->sendMessage($chatId, $response);

        // Kirim daftar ID untuk foto
        $copyText = "📸 <b>Kirim foto — salin ID di bawah:</b>\n\n";
        foreach ($reportIds as $report) {
            $copyText .= "<code>{$report->telegram_report_id}</code>\n";
        }
        $copyText .= "\n📌 <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $copyText .= "Atau kirim foto dengan caption ID di atas ⬆️";
        $this->telegram->sendMessage($chatId, $copyText);
    }
    protected function processClarificationReply(string $text, string $chatId, Employee $employee, TelegramBotLog $log, array $activeSession): void
    {        $sessionId = $activeSession['session_id'];
        $text = trim($text);

        // Cek apakah user mau skip semua
        if (in_array(strtolower($text), ['lewati', 'skip', 'tidak ada', '0'])) {
            // Skip semua item ambigu
            $result = \App\Services\AI\ClarificationSessionManager::skipAllItems($sessionId);
            if (!$result['success']) {
                $this->telegram->sendMessage($chatId, "❌ " . ($result['error'] ?? 'Terjadi kesalahan.'));
                return;
            }
            $session = $result['session'];
            // Selesai, simpan
            $this->finalizeSession($sessionId, $session, $chatId, $employee, $log);
            return;
        }

        // Parse format: "1.A 2.C 3.B 4.A" atau "1.A 2.C" atau "1.A"
        $pattern = '/(\d+)\s*[\.\:\-]?\s*([A-Ha-h])/';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            // Coba format alternatif: "A1" atau "1A"
            $pattern2 = '/(\d+)\s*([A-Ha-h])/';
            preg_match_all($pattern2, $text, $matches2, PREG_SET_ORDER);
            if (!empty($matches2)) {
                $matches = $matches2;
            } else {
                $this->telegram->sendMessage($chatId,
                    "⚠️ Format tidak dikenali.\n\n" .
                    "Gunakan format: <code>1.A 2.C 3.B 4.A</code>\n" .
                    "Atau: <code>1.A</code> untuk satu item\n" .
                    "Atau ketik <b>lewati</b> untuk skip semua."
                );
                return;
            }
        }

        // Proses setiap pilihan user
        $results = [];
        foreach ($matches as $m) {
            $itemNum = (int)$m[1];
            $letter = strtoupper($m[2]);
            $results[] = \App\Services\AI\ClarificationSessionManager::processItemByLetter(
                $sessionId, $itemNum - 1, $letter // itemNum 1-based -> 0-based index
            );
        }

        // Cek hasil
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        foreach ($results as $r) {
            if ($r['success']) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = $r['error'] ?? 'Error';
            }
        }

        if ($errorCount > 0) {
            $this->telegram->sendMessage($chatId,
                "⚠️ {$successCount} sukses, {$errorCount} gagal:\n" .
                implode("\n", array_slice($errors, 0, 3))
            );
        }

        // Cek apakah semua item sudah terisi
        $session = \App\Services\AI\ClarificationSessionManager::getSession($sessionId);
        if (!$session) {
            $this->telegram->sendMessage($chatId, "❌ Sesi telah kadaluarsa.");
            return;
        }

        // Cek apakah semua item sudah resolved
        $allResolved = true;
        foreach ($session['items'] as $item) {
            if (empty($item['resolved_asset_id'])) {
                $allResolved = false;
                break;
            }
        }

        if ($allResolved) {
            // Semua selesai
            $this->finalizeSession($sessionId, $session, $chatId, $employee, $log);
        } else {
            // Masih ada yang belum
            $remainingItems = [];
            foreach ($session['items'] as $idx => $item) {
                if (empty($item['resolved_asset_id'])) {
                    $remainingItems[] = ($idx + 1) . ". " . ($item['raw_text'] ?? 'Item ' . ($idx+1));
                }
            }
            $this->telegram->sendMessage($chatId,
                "✅ {$successCount} item dikonfirmasi!\n\n" .
                "Masih ada " . count($remainingItems) . " item:\n" .
                implode("\n", $remainingItems) . "\n\n" .
                "Balas dengan format: <code>1.A 2.C 3.B</code>"
            );
        }
    }

    /**
     * Finalisasi session — simpan semua report, kirim summary
     */
    protected function finalizeSession(string $sessionId, array $session, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        // Simpan semua report
        $savedIds = \App\Services\AI\ClarificationSessionManager::saveAllReports($session);

        if (!empty($savedIds)) {
            $log->update(['maintenance_report_id' => $savedIds[0]]);
        }

        // Build summary
        $summary = \App\Services\AI\ClarificationSessionManager::getSummary($session);
        $summaryMsg = \App\Services\AI\ClarificationSessionManager::buildSummaryMessage($summary, $employee);
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
        $response = "✅ <b>Laporan berhasil disimpan!</b>\n\n";
        $response .= "Shift: {$parsed['shift']}\n";
        $response .= "Tanggal: {$parsed['date']->format('d/m/Y')}\n";
        $response .= "Teknisi: {$employee->name}\n\n";

        foreach ($reportIds as $i => $report) {
            $statusEmoji = match($report->status) {
                'done' => '✅',
                'continue' => '🔄',
                'pending' => '⏳',
                default => '📋',
            };

            $assetName = $report->asset ? $report->asset->tech_ident_no : '⚠️ (tidak dikenal)';
            $suffix = '';
            if ($report->ai_suggested) {
                $pct = round(($report->ai_confidence ?? 0) * 100);
                $suffix = " 🤖{$pct}%";
            }
            $response .= "{$statusEmoji} <b>{$report->telegram_report_id}</b>\n";
            $response .= "   └ {$report->action_taken}\n";
            $response .= "   └ Alat: {$assetName}{$suffix} [{$report->status}]\n\n";
        }

        $unknownCount = collect($reportIds)->filter(fn($r) => !$r->asset_id)->count();
        if ($unknownCount > 0) {
            $response .= "\n⚠️ <b>{$unknownCount} alat tidak dikenal.</b> Silakan mapping manual di panel.";
        }

        $this->telegram->sendMessage($chatId, $response);

        // Kirim PESAN KEDUA — daftar ID
        $copyText = "📸 <b>Kirim foto — salin ID di bawah:</b>\n\n";
        foreach ($reportIds as $report) {
            $copyText .= "<code>{$report->telegram_report_id}</code>\n";
        }
        $copyText .= "\n📌 <b>Cara:</b> Reply pesan ini + attach foto.\n";
        $copyText .= "Atau kirim foto dengan caption ID di atas ⬆️";

        $this->telegram->sendMessage($chatId, $copyText);
    }

    /**
     * Handle foto dari user — hanya attach ke ID yang disebut di caption atau reply
     */
    protected function handlePhotoMessage(array $message, string $chatId, ?Employee $employee, TelegramBotLog $log): void
    {
        $replyTo = $message['reply_to_message'] ?? null;
        $caption = trim($message['caption'] ?? '');

        // PRIORITAS 1: ID dari CAPTION foto (yang paling spesifik)
        $reportIds = [];
        if (!empty($caption)) {
            preg_match_all('/LMS-\d{8}-\d{3}/', $caption, $captionMatches);
            $reportIds = $captionMatches[0] ?? [];
        }

        // PRIORITAS 2: Jika tidak ada ID di caption, ambil dari reply (hanya 1 ID pertama yang cocok)
        if (empty($reportIds) && $replyTo) {
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';
            // Cari ID yang sepertinya baru — ambil yang prefix-nya cocok dengan hari ini
            preg_match_all('/LMS-\d{8}-\d{3}/', $replyText, $replyMatches);
            $allIds = $replyMatches[0] ?? [];

            if (!empty($allIds)) {
                // Ambil hanya 1 ID terakhir/terbawah (yang paling baru)
                $reportIds = [end($allIds)];
            }
        }

        if (empty($reportIds)) {
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>ID laporan tidak ditemukan.</b>\n\n" .
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
            $this->telegram->sendMessage($chatId, "❌ Gagal mengunduh foto. Silakan coba lagi.");
            return;
        }

        $attachedTo = [];

        foreach ($reportIds as $rid) {
            $report = MaintenanceReport::where('telegram_report_id', $rid)->first();

            if (!$report) {
                $attachedTo[] = "❌ {$rid} tidak ditemukan";
                continue;
            }

            // Validasi: hanya teknisi yang bersangkutan
            if ($employee && $report->employee_id !== $employee->id) {
                $attachedTo[] = "⛔ {$rid} bukan laporan Anda";
                continue;
            }

            // Cek apakah foto ini sudah pernah di-attach (hindari duplikasi)
            $existingDocs = json_decode($report->documents ?? '[]', true);
            if (in_array($photoPath, $existingDocs)) {
                $attachedTo[] = "⏭️ {$rid} — foto sudah ada";
                continue;
            }

            // Attach foto ke laporan ini
            $this->photoHandler->attachPhotoToReport($report, $photoPath);
            $docCount = count(json_decode($report->fresh()->documents ?? '[]', true));
            $attachedTo[] = "✅ {$rid} ({$docCount} file)";
        }

        $this->telegram->sendMessage($chatId,
            "✅ <b>Foto tersimpan!</b>\n\n" .
            implode("\n", $attachedTo)
        );

        // Update log — simpan ID pertama yang berhasil
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
            'incoming_message' => "📸 Foto untuk " . implode(', ', $reportIds),
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
                "❌ <b>Nomor tidak terdaftar!</b>\n\n" .
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
                    "🔄 <b>Akun dipindahkan ke chat ini.</b>\n" .
                    "Selamat datang kembali, {$employee->name}!"
                );
                return;
            } else {
                $this->telegram->sendMessage($chatId,
                    "⚠️ <b>Akun ini sudah terdaftar di chat lain.</b>\n\n" .
                    "Hubungi admin untuk memverifikasi ulang."
                );
                return;
            }
        }

        // Daftarkan
        $employee->update(['telegram_id' => $chatId]);

        $this->telegram->sendMessage($chatId,
            "✅ <b>Pendaftaran berhasil!</b>\n\n" .
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
                "📋 <b>Cek Status Laporan</b>\n\n" .
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
                "❌ Laporan dengan ID <b>{$args}</b> tidak ditemukan."
            );
            return;
        }

        $statusEmoji = match($report->status) {
            'done' => '✅',
            'continue' => '🔄',
            'pending' => '⏳',
            default => '📋',
        };

        $response = "📋 <b>Detail Laporan</b>\n\n";
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
            "🤖 <b>Selamat datang di Bot Laporan Maintenance!</b>\n\n" .
            "Bot ini digunakan untuk mencatat laporan perbaikan harian.\n\n" .
            "📋 <b>Format laporan:</b>\n" .
            "<code>Report shift 1\n" .
            "17/05/2026\n" .
            "1. Aksi perbaikan - done\n" .
            "2. Aksi lain (continue)</code>\n\n" .
            "📸 <b>Kirim foto:</b>\n" .
            "Reply pesan bot dengan ID laporan + foto.\n\n" .
            "🔹 /register — Daftarkan akun Anda\n" .
            "🔹 /status [ID] — Cek status laporan\n" .
            "🔹 /me — Lihat profil Anda\n" .
            "🔹 /help — Bantuan lengkap\n\n" .
            "📞 Hubungi admin jika ada kendala."
        );
    }

    /**
     * Kirim bantuan lengkap
     */
    protected function sendHelp(string $chatId): void
    {
        $this->telegram->sendMessage($chatId,
            "📚 <b>Panduan Penggunaan Bot</b>\n\n" .
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
