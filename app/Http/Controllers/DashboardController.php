<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\MaintenanceReport;
use App\Models\TelegramBotLog;
use App\Models\TelegramSetting;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $totalAssets = Asset::count();
        $totalEmployees = Employee::count();
        $totalMaintenance = MaintenanceReport::count();

        // Ambil beberapa statistik terbaru untuk ditampilkan
        $recentReports = MaintenanceReport::with('asset', 'employee')
            ->latest()
            ->take(5)
            ->get();

        // --- DATA BOT TELEGRAM ---
        $botStatus = TelegramSetting::getValue('bot_status', 'inactive');
        $todayBotLogs = TelegramBotLog::whereDate('created_at', Carbon::today())
            ->latest()
            ->take(10)
            ->get();
        $todayBotCount = TelegramBotLog::whereDate('created_at', Carbon::today())->count();
        $todayBotSuccess = TelegramBotLog::whereDate('created_at', Carbon::today())
            ->where('parsing_status', 'success')->count();
        $todayBotFailed = TelegramBotLog::whereDate('created_at', Carbon::today())
            ->where('parsing_status', 'failed')->count();

        // --- DATA AI PROVIDERS ---
        $aiProviders = AiProvider::orderBy('priority_order')->get();
        $todayAiLogs = AiUsageLog::with('provider')
            ->whereDate('created_at', Carbon::today())
            ->latest()
            ->take(10)
            ->get();
        $todayAiCount = AiUsageLog::whereDate('created_at', Carbon::today())->count();
        $totalAiTokensToday = AiUsageLog::whereDate('created_at', Carbon::today())->sum('total_tokens');

        return view('dashboard', compact(
            'totalAssets',
            'totalEmployees',
            'totalMaintenance',
            'recentReports',
            // Bot
            'botStatus',
            'todayBotLogs',
            'todayBotCount',
            'todayBotSuccess',
            'todayBotFailed',
            // AI
            'aiProviders',
            'todayAiLogs',
            'todayAiCount',
            'totalAiTokensToday'
        ));
    }
}
