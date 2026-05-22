<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Monitoring Maintenance & Asset Manager</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .card-hover { transition: box-shadow 0.2s ease; }
        .card-hover:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }
        .status-dot.active { background: #16a34a; box-shadow: 0 0 6px rgba(22,163,74,0.4); }
        .status-dot.inactive { background: #94a3b8; }
        .status-dot.maintenance { background: #f59e0b; }
        .status-dot.error { background: #dc2626; }
        .scroll-container {
            max-height: 220px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .scroll-container::-webkit-scrollbar { width: 4px; }
        .scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">

    <!-- NAVBAR ATAS -->
    <header class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-slate-900">
                    GAWI<span class="text-blue-600">OLEOCHEMICAL</span>
                </h1>
                <p class="text-xs text-slate-400 font-medium uppercase tracking-widest mt-0.5">
                    Sistem Monitoring Maintenance &amp; Asset Manager
                </p>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-xs text-slate-400 font-medium bg-slate-100 px-3 py-1.5 rounded-full">
                    v1.0 — Industrial Grade
                </span>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-10">

        <!-- GREETING SECTION -->
        <div class="mb-10">
            <h2 class="text-3xl font-bold text-slate-800">Selamat Datang</h2>
            <p class="text-slate-500 mt-1 text-sm">
                Pantau dan kelola seluruh aset pabrik, teknisi, dan laporan perbaikan dalam satu dashboard terpadu.
            </p>
        </div>

        <!-- STATS CARDS (3 BESAR) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <!-- Card: Total Aset -->
            <a href="{{ route('assets.index') }}" class="block bg-blue-600 rounded-xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-blue-200 text-xs font-medium uppercase tracking-widest mb-1">Total Aset</p>
                        <p class="text-4xl font-black">{{ $totalAssets }}</p>
                        <p class="text-blue-200 text-xs mt-2">Equipment &amp; Alat Terdaftar</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-lg flex items-center justify-center text-2xl">🔧</div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-blue-200 font-medium">
                    <span>Kelola Aset →</span>
                </div>
            </a>

            <!-- Card: Total Teknisi -->
            <a href="{{ route('employees.index') }}" class="block bg-emerald-600 rounded-xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-emerald-200 text-xs font-medium uppercase tracking-widest mb-1">Total Teknisi</p>
                        <p class="text-4xl font-black">{{ $totalEmployees }}</p>
                        <p class="text-emerald-200 text-xs mt-2">Karyawan Maintenance</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-lg flex items-center justify-center text-2xl">👷</div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-emerald-200 font-medium">
                    <span>Kelola Teknisi →</span>
                </div>
            </a>

            <!-- Card: Total Laporan -->
            <a href="{{ route('maintenance.detail') }}" class="block bg-purple-600 rounded-xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-purple-200 text-xs font-medium uppercase tracking-widest mb-1">Laporan Perbaikan</p>
                        <p class="text-4xl font-black">{{ $totalMaintenance }}</p>
                        <p class="text-purple-200 text-xs mt-2">Total Record Maintenance</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-lg flex items-center justify-center text-2xl">📋</div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-purple-200 font-medium">
                    <span>Lihat Laporan →</span>
                </div>
            </a>
        </div>

        <!-- GRID 3 KOLOM: Quick Access + Bot + AI -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">

            <!-- ==================== QUICK ACCESS ==================== -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-700 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                    Akses Cepat
                </h3>
                <div class="space-y-2.5">
                    <a href="{{ route('assets.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-blue-50 transition-colors border border-transparent hover:border-blue-100">
                        <div class="w-9 h-9 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-base">📦</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Asset Manager</p>
                            <p class="text-[11px] text-slate-400">Import/Export &amp; Filter Aset</p>
                        </div>
                    </a>
                    <a href="{{ route('maintenance.detail') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-purple-50 transition-colors border border-transparent hover:border-purple-100">
                        <div class="w-9 h-9 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center text-base">📊</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Maintenance Detail</p>
                            <p class="text-[11px] text-slate-400">Grafik &amp; Log Perbaikan</p>
                        </div>
                    </a>
                    <a href="{{ route('employees.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-emerald-50 transition-colors border border-transparent hover:border-emerald-100">
                        <div class="w-9 h-9 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center text-base">👷</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Manajemen Teknisi</p>
                            <p class="text-[11px] text-slate-400">Data Karyawan &amp; Koneksi Bot</p>
                        </div>
                    </a>
                    <a href="{{ route('reports.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-amber-50 transition-colors border border-transparent hover:border-amber-100">
                        <div class="w-9 h-9 bg-amber-100 text-amber-600 rounded-lg flex items-center justify-center text-base">📋</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Manajemen Laporan</p>
                            <p class="text-[11px] text-slate-400">Semua Laporan Perbaikan</p>
                        </div>
                    </a>
                    <a href="{{ route('telegram.control') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-cyan-50 transition-colors border border-transparent hover:border-cyan-100">
                        <div class="w-9 h-9 bg-cyan-100 text-cyan-600 rounded-lg flex items-center justify-center text-base">🤖</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Panel Bot Telegram</p>
                            <p class="text-[11px] text-slate-400">Kontrol &amp; Monitoring Bot</p>
                        </div>
                    </a>
                    <a href="{{ route('ai-providers.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-indigo-50 transition-colors border border-transparent hover:border-indigo-100">
                        <div class="w-9 h-9 bg-indigo-100 text-indigo-600 rounded-lg flex items-center justify-center text-base">🧠</div>
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Panel AI Providers</p>
                            <p class="text-[11px] text-slate-400">Groq, OpenAI &amp; Fallback</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- ==================== BOT TELEGRAM ==================== -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-700 flex items-center gap-2">
                        <span>🤖</span> Bot Telegram
                    </h3>
                    <span class="flex items-center gap-1.5 text-[11px] font-medium
                        {{ $botStatus == 'active' ? 'text-emerald-600' : ($botStatus == 'maintenance' ? 'text-amber-600' : 'text-slate-400') }}">
                        <span class="status-dot {{ $botStatus }}"></span>
                        {{ $botStatus == 'active' ? 'Aktif' : ($botStatus == 'maintenance' ? 'Pemeliharaan' : 'Nonaktif') }}
                    </span>
                </div>

                <!-- Statistik Hari Ini -->
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-slate-50 rounded-lg p-3 text-center">
                        <div class="text-lg font-black text-slate-700">{{ $todayBotCount }}</div>
                        <div class="text-[10px] text-slate-400 font-medium uppercase">Total</div>
                    </div>
                    <div class="bg-emerald-50 rounded-lg p-3 text-center">
                        <div class="text-lg font-black text-emerald-600">{{ $todayBotSuccess }}</div>
                        <div class="text-[10px] text-emerald-500 font-medium uppercase">Sukses</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <div class="text-lg font-black text-red-600">{{ $todayBotFailed }}</div>
                        <div class="text-[10px] text-red-500 font-medium uppercase">Gagal</div>
                    </div>
                </div>

                <!-- Log Hari Ini -->
                <div class="scroll-container">
                    @forelse($todayBotLogs as $log)
                    <div class="flex items-start gap-2.5 py-2 border-b border-slate-100 last:border-b-0">
                        <span class="text-[10px] text-slate-400 font-mono w-12 shrink-0 mt-0.5">
                            {{ $log->created_at->format('H:i') }}
                        </span>
                        <span class="text-xs text-slate-700 truncate flex-1">
                            {{ Str::limit($log->incoming_message ?? $log->message_type, 28) }}
                        </span>
                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded shrink-0
                            {{ $log->parsing_status == 'success' ? 'bg-emerald-100 text-emerald-700' : ($log->parsing_status == 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-500') }}">
                            {{ $log->parsing_status }}
                        </span>
                    </div>
                    @empty
                    <p class="text-xs text-slate-400 italic text-center py-4">Belum ada aktivitas bot hari ini.</p>
                    @endforelse
                </div>

                <a href="{{ route('telegram.control') }}" class="mt-3 block text-center text-xs font-semibold text-cyan-600 bg-cyan-50 border border-cyan-200 rounded-lg py-2 hover:bg-cyan-100 transition-colors">
                    🔧 Buka Panel Bot
                </a>
            </div>

            <!-- ==================== AI PROVIDERS ==================== -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-700 flex items-center gap-2">
                        <span>🧠</span> AI Providers
                    </h3>
                    <span class="text-[11px] font-medium text-slate-400">
                        {{ $todayAiCount }} call · {{ number_format($totalAiTokensToday) }} token
                    </span>
                </div>

                <!-- Provider Status Cards -->
                <div class="space-y-2.5 mb-4">
                    @forelse($aiProviders as $provider)
                    <div class="flex items-center justify-between p-2.5 rounded-lg border
                        {{ $provider->is_active ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50' }}">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="status-dot
                                {{ $provider->status_label == 'aktif' ? 'active' : '' }}
                                {{ in_array($provider->status_label, ['habis','kritis','error']) ? 'error' : '' }}
                                {{ in_array($provider->status_label, ['menipis','nonaktif']) ? 'inactive' : '' }}
                            "></span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-slate-700 truncate">{{ $provider->name }}</p>
                                <p class="text-[10px] text-slate-400">
                                    {{ $provider->model_used ?? '-' }}
                                    @if($provider->max_monthly_tokens)
                                        · {{ number_format($provider->current_month_tokens ?? 0) }}/{{ number_format($provider->max_monthly_tokens) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if($provider->max_monthly_tokens && $provider->max_monthly_tokens > 0)
                            <div class="w-12 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                @php $pct = $provider->getTokenUsagePercentage() ?? 0; @endphp
                                <div class="h-full rounded-full transition-all
                                    {{ $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                    style="width: {{ min($pct, 100) }}%"></div>
                            </div>
                            @endif
                            <span class="text-[10px] font-medium px-1.5 py-0.5 rounded whitespace-nowrap
                                {{ $provider->status_color == 'green' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                {{ $provider->status_color == 'yellow' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $provider->status_color == 'orange' ? 'bg-orange-100 text-orange-700' : '' }}
                                {{ $provider->status_color == 'red' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $provider->status_color == 'gray' ? 'bg-slate-100 text-slate-500' : '' }}
                            ">
                                {{ $provider->status_label }}
                            </span>
                        </div>
                    </div>
                    @empty
                    <p class="text-xs text-slate-400 italic text-center py-3">Belum ada provider AI.</p>
                    @endforelse
                </div>

                <!-- Log AI Hari Ini -->
                <div class="scroll-container border-t border-slate-100 pt-3">
                    <p class="text-[10px] font-semibold text-slate-400 uppercase mb-2">Log Hari Ini</p>
                    @forelse($todayAiLogs as $log)
                    <div class="flex items-center gap-2 py-1.5">
                        <span class="text-[10px] text-slate-400 font-mono w-14 shrink-0">
                            {{ $log->created_at->format('H:i') }}
                        </span>
                        <span class="text-[11px] text-slate-600 truncate flex-1">
                            {{ $log->provider?->name ?? 'N/A' }}
                        </span>
                        <span class="text-[10px] text-slate-400 shrink-0">
                            {{ $log->total_tokens ? number_format($log->total_tokens) . ' tk' : '-' }}
                        </span>
                    </div>
                    @empty
                    <p class="text-xs text-slate-400 italic text-center py-2">Belum ada pemakaian AI hari ini.</p>
                    @endforelse
                </div>

                <a href="{{ route('ai-providers.index') }}" class="mt-3 block text-center text-xs font-semibold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg py-2 hover:bg-indigo-100 transition-colors">
                    ⚙️ Buka Panel AI
                </a>
            </div>

        </div>

        <!-- ROW 2: Aktivitas Terbaru -->
        <div class="grid grid-cols-1 lg:grid-cols-1 gap-6 mb-10">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-700 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
                    Aktivitas Maintenance Terbaru
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
                    @forelse($recentReports as $report)
                    <div class="flex items-start gap-3 p-3 rounded-lg border border-slate-100 hover:border-slate-200 hover:bg-slate-50 transition-colors">
                        <div class="w-9 h-9 bg-slate-100 rounded-lg flex items-center justify-center text-xs font-bold text-slate-500 shrink-0">
                            {{ date('d', strtotime($report->report_date)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-slate-700 truncate">
                                {{ $report->asset?->tech_ident_no ?? 'N/A' }}
                            </p>
                            <p class="text-[10px] text-slate-400 truncate">
                                {{ Str::limit($report->action_taken ?: $report->raw_text, 35) }}
                            </p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-[10px] text-slate-400">
                                    {{ date('d M', strtotime($report->report_date)) }}
                                </span>
                                @php $s = strtolower($report->status); @endphp
                                <span class="text-[9px] font-medium px-1 py-0.5 rounded
                                    {{ $s == 'done' ? 'bg-emerald-100 text-emerald-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                    {{ $s }}
                                </span>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="col-span-full text-center py-8 text-slate-400 italic text-sm">
                        Belum ada aktivitas maintenance.
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="border-t border-slate-200 pt-6 mt-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-xs text-slate-400">
                    &copy; {{ date('Y') }} Oleochemical Pro — Industrial Maintenance System
                </p>
                <p class="text-xs text-slate-400">
                    Powered by <span class="font-bold text-slate-600">Erik Adam - IT Support</span>
                </p>
            </div>
        </div>

    </main>

</body>
</html>
