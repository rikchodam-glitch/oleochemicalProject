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
        $updateId = $update['update_id'] ?? null;

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
     */
    protected function handleReportText(string $text, string $chatId, Employee $employee, TelegramBotLog $log): void
    {
        $this->telegram->sendChatAction($chatId);

        // Cek apakah ini nomor HP untuk registrasi
        if (preg_match('/^(0|62)\d{8,15}$/', trim($text))) {
            $this->processRegistration($text, $chatId, $log);
            return;
        }

        // 🔥 CEK APAKAH USER SEDANG DALAM SESI KLARIFIKASI
        $activeSession = \App\Services\AI\ClarificationSessionManager::getActiveSession((int)$chatId);
        if ($activeSession && $activeSession['status'] === 'waiting_user') {
            $this->processClarificationReply($text, $chatId, $employee, $log, $activeSession);
            return;
        }

        // Parse teks laporan
        $parsed = $this->parser->parse($text);

        if (empty($parsed['items'])) {
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Format laporan tidak dikenali.</b>\n\n" .
                "Gunakan format:\n" .
                "<code>Report shift 1\n" .
                "DD/MM/YYYY\n" .
                "1. Aksi perbaikan - done\n" .
                "2. Aksi lain (continue)</code>\n\n" .
                "Ketik /help untuk panduan lengkap."
            );
            return;
        }

        // 🔥 MINTA AI ANALISA DENGAN MODE KLARIFIKASI
        $gateway = new AiGatewayService();
        $gateway->withContext($employee);

        $aiResult = $gateway->analyzeWithClarification($parsed['raw_text'], $employee);

        // Jika AI gagal, fallback ke parsing biasa
        if (empty($aiResult['success']) && empty($aiResult['is_ambiguous'])) {
            $this->processNormalReport($parsed, $text, $chatId, $employee, $log);
            return;
        }

        // Jika AI yakin (confidence >= 0.8) -> simpan langsung
        if (isset($aiResult['is_ambiguous']) && !$aiResult['is_ambiguous'] && ($aiResult['confidence'] ?? 0) >= 0.8) {
            $this->processNormalReport($parsed, $text, $chatId, $employee, $log, $aiResult);
            return;
        }

        // 🔥 AI AMBIGU -> MASUK MODE KLARIFIKASI
        $possibleAssets = $aiResult['possible_assets'] ?? [];
        $clarificationQuestion = $aiResult['clarification_question'] ?? 'Pilih equipment yang dimaksud:';

        // Buat session klarifikasi
        $session = \App\Services\AI\ClarificationSessionManager::createSession(
            (int)$chatId,
            $chatId,
            $aiResult,
            $employee
        );

        // Kirim pesan klarifikasi ke user
        $responseText = "🤖 <b>Analisa AI</b>\n\n";
        $responseText .= "Laporan: <i>" . e($text) . "</i>\n\n";

        if (!empty($possibleAssets)) {
            $responseText .= "Saya menemukan beberapa kemungkinan. Pilih yang sesuai:\n\n";

            $buttons = [];
            foreach ($possibleAssets as $index => $asset) {
                $num = $index + 1;
                $pct = round(($asset['confidence'] ?? 0) * 100);
                $loc = $asset['location'] ?? '';
                $label = "{$num}. {$asset['tech_ident_no']} - {$asset['description']}";
                if ($loc) $label .= " ({$loc})";
                $responseText .= "{$label} [{$pct}%]\n";

                // Simpan untuk inline keyboard
                $buttons[] = [
                    'text' => "{$num}️⃣ {$asset['description']}",
                    'callback_data' => "clarify_select:{$session['session_id']}:{$num}",
                ];
            }

            $responseText .= "\n<b>Cara memilih:</b>\n";
            $responseText .= "• Ketik angka (1, 2, 3...)\n";
            $responseText .= "• Atau ketik kode equipment (contoh: {$possibleAssets[0]['tech_ident_no']})\n";
            $responseText .= "• Atau ketik 'tidak ada' jika tidak cocok";

            // Opsi "Tidak ada" di keyboard
            $buttons[] = [
                'text' => '✍️ Tidak ada, ketik manual',
                'callback_data' => "clarify:none:{$session['session_id']}",
            ];
        } else {
            $responseText .= "{$clarificationQuestion}\n\n";
            $responseText .= "Silakan kirim detail tambahan.";
        }

        // Sisa percobaan
        $responseText .= "\n\n⏳ Sisa percobaan: {$session['max_attempts']}x";

        // Kirim dengan tombol inline jika ada opsi
        if (!empty($possibleAssets)) {
            $this->telegram->sendMessageWithKeyboard($chatId, $responseText, $buttons);
        } else {
            $this->telegram->sendMessage($chatId, $responseText);
        }
    }

    /**
     * Proses balasan klarifikasi dari user
     */
    protected function processClarificationReply(string $text, string $chatId, Employee $employee, TelegramBotLog $log, array $session): void
    {
        $result = \App\Services\AI\ClarificationSessionManager::processUserReply(
            $session['session_id'],
            $text
        );

        if (!$result['success']) {
            $this->telegram->sendMessage($chatId, "❌ " . ($result['error'] ?? 'Terjadi kesalahan. Silakan coba lagi.'));
            return;
        }

        if ($result['resolved']) {
            // ✅ User memilih asset -> simpan laporan
            $report = \App\Services\AI\ClarificationSessionManager::saveReportFromClarification(
                $result['session'],
                $result['asset']
            );

            $this->telegram->sendMessage($chatId,
                "✅ <b>Laporan dikonfirmasi!</b>\n\n" .
                "Equipment: <b>{$result['asset']['tech_ident_no']}</b>\n" .
                "Deskripsi: {$result['asset']['description']}\n" .
                "Lokasi: {$result['asset']['location']}\n\n" .
                "📋 Status: <b>Perbaikan selesai</b>\n" .
                "🧠 AI belajar: laporan serupa akan langsung dikenali lain kali.\n\n" .
                "Terima kasih, {$employee->name}! 🙏"
            );

            // Update log
            if ($report) {
                $log->update(['maintenance_report_id' => $report->id]);
            }

            // Hapus session
            \App\Services\AI\ClarificationSessionManager::destroySession($session['session_id']);

        } elseif ($result['unidentified']) {
            // ❌ 3x gagal -> simpan sebagai unidentified
            $report = \App\Services\AI\ClarificationSessionManager::saveUnidentifiedReport($result['session']);

            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Laporan tidak teridentifikasi</b>\n\n" .
                "Maaf, setelah 3 kali percobaan tidak ketemu equipment yang cocok.\n" .
                "Laporan akan diteruskan ke admin untuk direview.\n\n" .
                "Jika ada informasi tambahan, silakan hubungi admin."
            );

            if ($report) {
                $log->update(['maintenance_report_id' => $report->id]);
            }

            \App\Services\AI\ClarificationSessionManager::destroySession($session['session_id']);

        } else {
            // Masih ada sisa percobaan
            $remaining = $result['remaining_attempts'] ?? 0;
            $error = $result['error'] ?? 'Pilihan tidak dikenali.';

            // Kirim ulang opsi
            $optionsText = "";
            foreach ($session['possible_assets'] as $i => $asset) {
                $num = $i + 1;
                $optionsText .= "{$num}. {$asset['tech_ident_no']} - {$asset['description']}\n";
            }

            $msg = "⚠️ <b>{$error}</b>\n\n";
            $msg .= "Opsi yang tersedia:\n{$optionsText}\n";
            $msg .= "Ketik angka atau kode equipment.\n";
            $msg .= "Sisa percobaan: {$remaining}x";

            $this->telegram->sendMessage($chatId, $msg);
        }
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
