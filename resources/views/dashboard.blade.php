<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Monitoring Maintenance & Asset Manager</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .card-gradient-1 { background: linear-gradient(135deg, #1e3a5f, #2563eb); }
        .card-gradient-2 { background: linear-gradient(135deg, #065f46, #16a34a); }
        .card-gradient-3 { background: linear-gradient(135deg, #5b21b6, #8b5cf6); }
        .card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.25); }
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
            <a href="{{ route('assets.index') }}" class="block card-gradient-1 rounded-2xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-blue-200 text-xs font-bold uppercase tracking-widest mb-1">Total Aset</p>
                        <p class="text-4xl font-black">{{ $totalAssets }}</p>
                        <p class="text-blue-200 text-xs mt-2">Equipment &amp; Alat Terdaftar</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-xl flex items-center justify-center text-2xl">
                        🔧
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-blue-200 font-medium">
                    <span>Kelola Aset →</span>
                </div>
            </a>

            <!-- Card: Total Teknisi -->
            <a href="{{ route('employees.index') }}" class="block card-gradient-2 rounded-2xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-green-200 text-xs font-bold uppercase tracking-widest mb-1">Total Teknisi</p>
                        <p class="text-4xl font-black">{{ $totalEmployees }}</p>
                        <p class="text-green-200 text-xs mt-2">Karyawan Maintenance</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-xl flex items-center justify-center text-2xl">
                        👷
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-green-200 font-medium">
                    <span>Kelola Teknisi →</span>
                </div>
            </a>

            <!-- Card: Total Laporan -->
            <a href="{{ route('maintenance.detail') }}" class="block card-gradient-3 rounded-2xl p-6 text-white card-hover">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-purple-200 text-xs font-bold uppercase tracking-widest mb-1">Laporan Perbaikan</p>
                        <p class="text-4xl font-black">{{ $totalMaintenance }}</p>
                        <p class="text-purple-200 text-xs mt-2">Total Record Maintenance</p>
                    </div>
                    <div class="w-14 h-14 bg-white/15 rounded-xl flex items-center justify-center text-2xl">
                        📋
                    </div>
                </div>
                <div class="mt-5 flex items-center gap-2 text-xs text-purple-200 font-medium">
                    <span>Lihat Laporan →</span>
                </div>
            </a>
        </div>

        <!-- QUICK ACCESS + RECENT ACTIVITY -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
            <!-- Quick Access Card -->
            <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                    Akses Cepat
                </h3>
                <div class="space-y-3">
                    <a href="{{ route('assets.index') }}" class="flex items-center gap-4 p-3 rounded-xl hover:bg-blue-50 transition-colors border border-transparent hover:border-blue-100">
                        <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-lg">🔧</div>
                        <div>
                            <p class="text-sm font-bold text-slate-700">Asset Manager</p>
                            <p class="text-xs text-slate-400">Import/Export &amp; Filter Data Aset</p>
                        </div>
                    </a>
                    <a href="{{ route('maintenance.detail') }}" class="flex items-center gap-4 p-3 rounded-xl hover:bg-purple-50 transition-colors border border-transparent hover:border-purple-100">
                        <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center text-lg">📊</div>
                        <div>
                            <p class="text-sm font-bold text-slate-700">Maintenance Report</p>
                            <p class="text-xs text-slate-400">Grafik &amp; Log Perbaikan Detail</p>
                        </div>
                    </a>
                    <a href="{{ route('employees.index') }}" class="flex items-center gap-4 p-3 rounded-xl hover:bg-green-50 transition-colors border border-transparent hover:border-green-100">
                        <div class="w-10 h-10 bg-green-100 text-green-600 rounded-lg flex items-center justify-center text-lg">👷</div>
                        <div>
                            <p class="text-sm font-bold text-slate-700">Manajemen Teknisi</p>
                            <p class="text-xs text-slate-400">Data Karyawan &amp; Koneksi Bot</p>
                        </div>
                    </a>
                    <a href="{{ route('telegram.control') }}" class="flex items-center gap-4 p-3 rounded-xl hover:bg-cyan-50 transition-colors border border-transparent hover:border-cyan-100">
                        <div class="w-10 h-10 bg-cyan-100 text-cyan-600 rounded-lg flex items-center justify-center text-lg">🤖</div>
                        <div>
                            <p class="text-sm font-bold text-slate-700">Bot Telegram</p>
                            <p class="text-xs text-slate-400">Panel Kontrol &amp; Monitoring Bot</p>
                        </div>
                    </a>
                    <a href="{{ route('ai-providers.index') }}" class="flex items-center gap-4 p-3 rounded-xl hover:bg-indigo-50 transition-colors border border-transparent hover:border-indigo-100">
                        <div class="w-10 h-10 bg-indigo-100 text-indigo-600 rounded-lg flex items-center justify-center text-lg">🧠</div>
                        <div>
                            <p class="text-sm font-bold text-slate-700">Panel AI Providers</p>
                            <p class="text-xs text-slate-400">Groq, OpenAI &amp; Fallback Chain</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Statistik Tipe Aset -->
            <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 bg-emerald-600 rounded-full"></span>
                    Tipe Aset Terbanyak
                </h3>
                <div class="space-y-3">
                    @forelse($assetGroupCount->take(6) as $group)
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600 truncate mr-2">{{ $group->object_type }}</span>
                        <div class="flex items-center gap-2">
                            <div class="w-24 bg-slate-100 rounded-full h-2 overflow-hidden">
                                @php
                                    $maxCount = $assetGroupCount->max('total');
                                    $width = $maxCount > 0 ? round(($group->total / $maxCount) * 100) : 0;
                                @endphp
                                <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ $width }}%"></div>
                            </div>
                            <span class="text-xs font-bold text-slate-500 w-6 text-right">{{ $group->total }}</span>
                        </div>
                    </div>
                    @empty
                    <p class="text-sm text-slate-400 italic">Belum ada data aset.</p>
                    @endforelse
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
                    Aktivitas Terbaru
                </h3>
                <div class="space-y-3">
                    @forelse($recentReports as $report)
                    <div class="flex items-start gap-3 pb-3 border-b border-slate-100 last:border-b-0">
                        <div class="w-8 h-8 bg-slate-100 rounded-lg flex items-center justify-center text-xs font-bold text-slate-500 shrink-0 mt-0.5">
                            {{ date('d', strtotime($report->report_date)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-700 truncate">
                                {{ $report->asset?->tech_ident_no ?? 'N/A' }}
                            </p>
                            <p class="text-[11px] text-slate-400 truncate">
                                {{ Str::limit($report->action_taken ?: $report->raw_text, 40) }}
                            </p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-[10px] text-slate-400">
                                    {{ date('d M Y', strtotime($report->report_date)) }}
                                </span>
                                @php $s = strtolower($report->status); @endphp
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full
                                    {{ $s == 'done' ? 'bg-green-100 text-green-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                    {{ $s }}
                                </span>
                            </div>
                        </div>
                    </div>
                    @empty
                    <p class="text-sm text-slate-400 italic">Belum ada aktivitas maintenance.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- FOOTER INFO -->
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
