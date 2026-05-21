<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\MaintenanceReport;
use App\Models\TelegramBlacklist;
use App\Models\TelegramBotLog;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TelegramControlPanelController extends Controller
{
    protected TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    /**
     * Halaman utama panel kontrol bot
     */
    public function index()
    {
        $botActive = $this->telegram->isBotActive();
        $webhookInfo = $this->telegram->getWebhookInfo();
        $botStatus = TelegramSetting::getValue('bot_status', 'active');

        // Ambil info bot dari Telegram API
        $botInfo = $this->getBotInfo();
        $botName = $botInfo['result']['first_name'] ?? 'Unknown Bot';
        $botUsername = $botInfo['result']['username'] ?? 'unknown_bot';
        $botToken = TelegramSetting::getValue('bot_token');
        $tokenStatus = !empty($botToken) && isset($botInfo['ok']) && $botInfo['ok'] ? 'valid' : 'invalid';

        // Statistik
        $totalRegistered = Employee::whereNotNull('telegram_id')->count();
        $totalUnregistered = Employee::whereNull('telegram_id')->count();
        $totalEmployees = Employee::count();

        // Log hari ini
        $todayLogs = TelegramBotLog::with('employee')
            ->whereDate('created_at', Carbon::today())
            ->latest()
            ->take(50)
            ->get();

        // Statistik log
        $todaySuccess = TelegramBotLog::whereDate('created_at', Carbon::today())
            ->where('parsing_status', 'success')->count();
        $todayFailed = TelegramBotLog::whereDate('created_at', Carbon::today())
            ->where('parsing_status', 'failed')->count();
        $todayTotal = TelegramBotLog::whereDate('created_at', Carbon::today())->count();

        // Laporan via Telegram
        $telegramReports = MaintenanceReport::with(['asset', 'employee'])
            ->where('source', 'telegram')
            ->latest()
            ->take(5)
            ->get();
        $totalTelegramReports = MaintenanceReport::where('source', 'telegram')->count();

        // Equipment yang tidak dikenal via Telegram
        $unknownAssets = MaintenanceReport::where('source', 'telegram')
            ->whereNull('asset_id')
            ->latest()
            ->take(10)
            ->get();

        // Blacklist
        $blacklist = TelegramBlacklist::with('blockedBy')->latest()->get();

        // Pending registrations (log yang menunggu)
        $pendingRegistrations = TelegramBotLog::where('message_type', 'text')
            ->whereNull('employee_id')
            ->where('parsing_status', 'pending')
            ->latest()
            ->take(20)
            ->get();

        return view('telegram-control', compact(
            'botActive',
            'botInfo',
            'botName',
            'botUsername',
            'botToken',
            'tokenStatus',
            'webhookInfo',
            'botStatus',
            'totalRegistered',
            'totalUnregistered',
            'totalEmployees',
            'todayLogs',
            'todaySuccess',
            'todayFailed',
            'todayTotal',
            'telegramReports',
            'totalTelegramReports',
            'unknownAssets',
            'blacklist',
            'pendingRegistrations',
        ));
    }

    /**
     * Update pengaturan bot
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'bot_token' => 'nullable|string',
            'bot_status' => 'in:active,inactive,maintenance',
            'auto_approve' => 'in:true,false',
            'max_items_per_report' => 'integer|min:1|max:100',
            'notification_new_report' => 'in:true,false',
            'webhook_url' => 'nullable|url',
        ]);

        // Update bot token jika diisi
        if ($request->filled('bot_token')) {
            TelegramSetting::setValue('bot_token', $request->bot_token);
            // Re-inisialisasi service dengan token baru
            $this->telegram = new TelegramService();
        }

        foreach ($request->except(['_token', 'bot_token']) as $key => $value) {
            TelegramSetting::setValue($key, $value);
        }

        // Jika webhook_url diisi, set webhook
        if ($request->filled('webhook_url')) {
            $this->telegram->setWebhook($request->webhook_url);
        }

        return redirect()->back()->with('success', 'Pengaturan berhasil diperbarui!');
    }

    /**
     * Test koneksi bot dan dapatkan info
     */
    public function testBotConnection()
    {
        $token = TelegramSetting::getValue('bot_token');
        if (empty($token)) {
            return redirect()->back()->with('error', 'Token bot belum diisi!');
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->get("https://api.telegram.org/bot{$token}/getMe");

            $result = $response->json();

            if ($result['ok'] ?? false) {
                $bot = $result['result'];
                return redirect()->back()->with('success',
                    "✅ Bot terhubung! Nama: <b>{$bot['first_name']}</b> (@{$bot['username']})"
                );
            }

            return redirect()->back()->with('error', "❌ Token tidak valid: {$result['description']}");

        } catch (\Throwable $e) {
            return redirect()->back()->with('error', "❌ Gagal terhubung ke Telegram: {$e->getMessage()}");
        }
    }

    /**
     * Set webhook
     */
    public function setWebhook(Request $request)
    {
        $request->validate(['url' => 'required|url']);
        $result = $this->telegram->setWebhook($request->url);

        if ($result['ok'] ?? false) {
            TelegramSetting::setValue('webhook_url', $request->url);
            return redirect()->back()->with('success', 'Webhook berhasil diset!');
        }

        return redirect()->back()->with('error', 'Gagal set webhook: ' . ($result['description'] ?? 'Unknown error'));
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook()
    {
        $this->telegram->deleteWebhook();
        TelegramSetting::setValue('webhook_url', '');
        return redirect()->back()->with('success', 'Webhook berhasil dihapus!');
    }

    /**
     * Approve registrasi user
     */
    public function approveRegistration(Request $request, $logId)
    {
        $log = TelegramBotLog::findOrFail($logId);
        $chatId = $log->telegram_chat_id;
        $phoneNumber = trim($log->incoming_message ?? '');

        // Cari employee
        $employee = Employee::where('phone_number', $phoneNumber)->first();

        if (!$employee) {
            return redirect()->back()->with('error', "Nomor {$phoneNumber} tidak ditemukan di database.");
        }

        $employee->update(['telegram_id' => $chatId]);
        $log->update(['employee_id' => $employee->id, 'parsing_status' => 'success']);

        // Kirim notifikasi ke user
        $this->telegram->sendMessage($chatId,
            "✅ <b>Pendaftaran Anda telah disetujui admin!</b>\n\n" .
            "Sekarang Anda bisa mengirim laporan maintenance."
        );

        return redirect()->back()->with('success', "Registrasi {$employee->name} telah disetujui!");
    }

    /**
     * Tolak registrasi user
     */
    public function rejectRegistration(Request $request, $logId)
    {
        $log = TelegramBotLog::findOrFail($logId);
        $chatId = $log->telegram_chat_id;

        $log->update(['parsing_status' => 'failed']);

        $this->telegram->sendMessage($chatId,
            "❌ <b>Pendaftaran ditolak.</b>\n\n" .
            "Nomor HP Anda tidak ditemukan di database.\n" .
            "Silakan hubungi admin untuk mendaftarkan nomor Anda."
        );

        return redirect()->back()->with('success', 'Registrasi ditolak.');
    }

    /**
     * Blacklist user
     */
    public function blockUser(Request $request)
    {
        $request->validate([
            'telegram_chat_id' => 'required',
            'reason' => 'nullable|string|max:255',
        ]);

        TelegramBlacklist::create([
            'telegram_chat_id' => $request->telegram_chat_id,
            'telegram_username' => $request->telegram_username,
            'reason' => $request->reason,
            'blocked_by_employee_id' => auth()->id() ?? 1, // fallback
        ]);

        // Kirim notifikasi ke user yang diblokir
        $this->telegram->sendMessage($request->telegram_chat_id,
            "⛔ <b>Anda telah diblokir dari bot ini.</b>\n\n" .
            "Alasan: " . ($request->reason ?? 'Tidak disebutkan') . "\n\n" .
            "Hubungi admin untuk informasi lebih lanjut."
        );

        return redirect()->back()->with('success', 'User berhasil diblokir!');
    }

    /**
     * Unblacklist user
     */
    public function unblockUser($id)
    {
        $blacklist = TelegramBlacklist::findOrFail($id);
        $blacklist->delete();

        return redirect()->back()->with('success', 'User berhasil di-unblock!');
    }

    /**
     * Re-process log yang gagal
     */
    public function reprocessLog($logId)
    {
        $log = TelegramBotLog::findOrFail($logId);
        $log->update(['parsing_status' => 'pending', 'error_message' => null]);

        return redirect()->back()->with('success', 'Log siap untuk diproses ulang.');
    }

    /**
     * Hapus log
     */
    public function deleteLog($logId)
    {
        TelegramBotLog::findOrFail($logId)->delete();
        return redirect()->back()->with('success', 'Log berhasil dihapus.');
    }

    /**
     * Hapus semua log bot
     */
    public function cleanLogs(Request $request)
    {
        try {
            $deleted = TelegramBotLog::query()->delete();

            if ($deleted > 0) {
                return redirect()->back()->with('success', "✅ {$deleted} log berhasil dihapus semua.");
            }

            return redirect()->back()->with('info', "Tidak ada log untuk dihapus.");
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', "Gagal menghapus log: {$e->getMessage()}");
        }
    }

    /**
     * Ambil info bot dari API Telegram
     */
    protected function getBotInfo(): array
    {
        try {
            $token = TelegramSetting::getValue('bot_token');
            if (empty($token)) {
                return ['ok' => false];
            }
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get("https://api.telegram.org/bot{$token}/getMe");
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
