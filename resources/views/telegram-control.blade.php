<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Kontrol Bot Telegram - GAWI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .status-dot.online { background-color: #22c55e; }
        .status-dot.offline { background-color: #ef4444; }
        .status-dot.maintenance { background-color: #f59e0b; }
        .log-entry { transition: all 0.2s; }
        .log-entry:hover { background-color: #f8fafc; }
        .accordion-body { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        .accordion-body.open { max-height: none; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">

    <!-- NAVBAR -->
    <header class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('dashboard') }}" class="text-blue-600 hover:underline font-bold flex items-center gap-2">
                    <span>&larr;</span> Dashboard
                </a>
                <span class="font-bold text-gray-400">|</span>
                <h1 class="text-xl font-black tracking-tight text-slate-900">
                    PANEL <span class="text-blue-600">BOT TELEGRAM</span>
                </h1>
            </div>
            <div class="flex items-center gap-4">
                <span id="botStatusIndicator" class="text-xs font-bold px-3 py-1.5 rounded-full flex items-center gap-2
                    {{ $botActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                    <span class="status-dot {{ $botActive ? 'online' : 'offline' }}"></span>
                    {{ $botActive ? 'ONLINE' : 'OFFLINE' }}
                </span>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">

        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6">{{ session('error') }}</div>
        @endif

        <!-- ==================== ROW 1: STATUS CARDS ==================== -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Status Bot</p>
                <p class="text-lg font-black mt-1 {{ $botActive ? 'text-green-600' : 'text-red-600' }}">
                    {{ $botActive ? '🟢 Online' : '🔴 Offline' }}
                </p>
                <p class="text-[10px] text-slate-400">Mode: {{ $botStatus }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Teknisi Terdaftar</p>
                <p class="text-lg font-black text-blue-600">{{ $totalRegistered }}/{{ $totalEmployees }}</p>
                <p class="text-[10px] text-slate-400">{{ $totalUnregistered }} belum koneksi</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Laporan via Bot</p>
                <p class="text-lg font-black text-purple-600">{{ $totalTelegramReports }}</p>
                <p class="text-[10px] text-slate-400">Total semua laporan</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Hari Ini</p>
                <p class="text-lg font-black text-emerald-600">{{ $todayTotal }}</p>
                <p class="text-[10px] text-slate-400">
                    ✅ {{ $todaySuccess }} success · ❌ {{ $todayFailed }} failed
                </p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Unknown Assets</p>
                <p class="text-lg font-black text-amber-600">{{ $unknownAssets->count() }}</p>
                <p class="text-[10px] text-slate-400">Butuh mapping manual</p>
            </div>
        </div>

        <!-- ==================== TABS ==================== -->
        <div class="mb-6 flex gap-2 flex-wrap border-b border-slate-200 pb-2">
            <button onclick="showTab('settings')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-blue-600 text-white" data-tab="settings">⚙️ Pengaturan</button>
            <button onclick="showTab('logs')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-slate-600 hover:bg-slate-50" data-tab="logs">📋 Log Aktivitas</button>
            <button onclick="showTab('reports')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-slate-600 hover:bg-slate-50" data-tab="reports">📊 Laporan Telegram</button>
            <button onclick="showTab('unknown')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-slate-600 hover:bg-slate-50" data-tab="unknown">⚠️ Unknown Assets</button>
            <button onclick="showTab('blacklist')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-slate-600 hover:bg-slate-50" data-tab="blacklist">🚫 Blacklist</button>
            <button onclick="showTab('registration')" class="tab-btn px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-slate-600 hover:bg-slate-50" data-tab="registration">
                📝 Pendaftaran
                @if($pendingRegistrations->count() > 0)
                    <span class="bg-red-500 text-white text-[10px] px-2 py-0.5 rounded-full ml-1">{{ $pendingRegistrations->count() }}</span>
                @endif
            </button>
        </div>

        <!-- ==================== TAB: SETTINGS ==================== -->
        <div id="tab-settings" class="tab-content">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h3 class="text-lg font-bold text-slate-700 mb-6">⚙️ Pengaturan Bot</h3>

                <form action="{{ route('telegram.settings') }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status Bot</label>
                        <select name="bot_status" class="w-full border-gray-300 rounded-lg p-2 border">
                            <option value="active" {{ $botStatus == 'active' ? 'selected' : '' }}>🟢 Active — Bot melayani permintaan</option>
                            <option value="inactive" {{ $botStatus == 'inactive' ? 'selected' : '' }}>🔴 Inactive — Bot mati total</option>
                            <option value="maintenance" {{ $botStatus == 'maintenance' ? 'selected' : '' }}>🟡 Maintenance — Bot dalam perawatan</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Auto-Approve Registrasi</label>
                        <select name="auto_approve" class="w-full border-gray-300 rounded-lg p-2 border">
                            <option value="true" {{ \App\Models\TelegramSetting::getValue('auto_approve') == 'true' ? 'selected' : '' }}>✅ Ya — Langsung approve</option>
                            <option value="false" {{ \App\Models\TelegramSetting::getValue('auto_approve') == 'false' ? 'selected' : '' }}>❌ Tidak — Butuh verifikasi admin</option>
                        </select>
                        <p class="text-[10px] text-slate-400 mt-1">Jika Tidak, admin harus approve manual dari tab Pendaftaran</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Item per Laporan</label>
                        <input type="number" name="max_items_per_report" value="{{ \App\Models\TelegramSetting::getValue('max_items_per_report', 20) }}"
                            class="w-full border-gray-300 rounded-lg p-2 border" min="1" max="100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notifikasi Laporan Baru</label>
                        <select name="notification_new_report" class="w-full border-gray-300 rounded-lg p-2 border">
                            <option value="true" {{ \App\Models\TelegramSetting::getValue('notification_new_report') == 'true' ? 'selected' : '' }}>✅ Aktif</option>
                            <option value="false" {{ \App\Models\TelegramSetting::getValue('notification_new_report') == 'false' ? 'selected' : '' }}>❌ Nonaktif</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Webhook URL</label>
                        <div class="flex gap-2">
                            <input type="url" name="webhook_url" value="{{ \App\Models\TelegramSetting::getValue('webhook_url') }}"
                                placeholder="https://domain-anda.com/api/telegram/webhook"
                                class="flex-1 border-gray-300 rounded-lg p-2 border">
                        </div>
                        <p class="text-[10px] text-slate-400 mt-1">
                            Webhook saat ini:
                            <span class="font-mono text-xs">{{ $webhookInfo['result']['url'] ?? '(belum diset)' }}</span>
                            @if(($webhookInfo['result']['pending_update_count'] ?? 0) > 0)
                                · <span class="text-amber-600 font-bold">{{ $webhookInfo['result']['pending_update_count'] }} pending updates</span>
                            @endif
                        </p>
                    </div>

                    <div class="md:col-span-2 flex gap-3">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-blue-700">
                            💾 Simpan Pengaturan
                        </button>
                        <a href="{{ route('telegram.set-webhook') }}" onclick="event.preventDefault(); document.getElementById('setWebhookForm').submit();"
                            class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-emerald-700">
                            🔄 Set Webhook
                        </a>
                        <a href="{{ route('telegram.delete-webhook') }}" onclick="event.preventDefault(); document.getElementById('deleteWebhookForm').submit();"
                            class="bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-red-200">
                            🗑️ Hapus Webhook
                        </a>
                    </div>
                </form>

                <form id="setWebhookForm" action="{{ route('telegram.set-webhook') }}" method="POST" class="hidden">
                    @csrf
                    <input type="hidden" name="url" value="{{ \App\Models\TelegramSetting::getValue('webhook_url') }}">
                </form>
                <form id="deleteWebhookForm" action="{{ route('telegram.delete-webhook') }}" method="POST" class="hidden">
                    @csrf
                </form>
            </div>
        </div>

        <!-- ==================== TAB: LOGS ==================== -->
        <div id="tab-logs" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-slate-700">📋 Log Aktivitas (Hari Ini)</h3>
                    <div class="flex gap-2">
                        <form action="{{ route('telegram.clean-logs') }}" method="POST" onsubmit="return confirm('⚠️ Hapus SEMUA log? Tindakan ini tidak bisa dibatalkan.')">
                            @csrf
                            <button type="submit" class="text-xs bg-red-100 text-red-700 px-3 py-1.5 rounded-lg hover:bg-red-200 font-bold">🗑️ Hapus Semua Log</button>
                        </form>
                    </div>
                </div>

                @if($todayLogs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr>
                                <th class="px-4 py-3">Waktu</th>
                                <th class="px-4 py-3">User</th>
                                <th class="px-4 py-3">Pesan</th>
                                <th class="px-4 py-3">Tipe</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($todayLogs as $log)
                            <tr class="log-entry">
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
                                    {{ $log->created_at->format('H:i:s') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs font-semibold">{{ $log->employee?->name ?? ($log->telegram_username ?? 'Unknown') }}</span>
                                    <span class="text-[10px] text-slate-400 block">{{ $log->telegram_chat_id }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs max-w-xs truncate">
                                    {{ Str::limit($log->incoming_message, 50) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[10px] font-bold px-2 py-1 rounded
                                        @switch($log->message_type)
                                            @case('command') bg-blue-100 text-blue-700 @break
                                            @case('text') bg-green-100 text-green-700 @break
                                            @case('photo') bg-purple-100 text-purple-700 @break
                                            @default bg-slate-100 text-slate-600
                                        @endswitch
                                    ">{{ $log->message_type }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @switch($log->parsing_status)
                                        @case('success')
                                            <span class="text-[10px] font-bold bg-green-100 text-green-700 px-2 py-1 rounded">✅ Success</span>
                                            @break
                                        @case('failed')
                                            <span class="text-[10px] font-bold bg-red-100 text-red-700 px-2 py-1 rounded" title="{{ $log->error_message }}">❌ Failed</span>
                                            @break
                                        @default
                                            <span class="text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-1 rounded">⏳ Pending</span>
                                    @endswitch
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex gap-1 justify-center">
                                        @if($log->parsing_status == 'failed')
                                        <form action="{{ route('telegram.reprocess', $log->id) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded hover:bg-amber-200 font-bold" title="Reprocess">🔄</button>
                                        </form>
                                        @endif
                                        <form action="{{ route('telegram.delete-log', $log->id) }}" method="POST" onsubmit="return confirm('Hapus log ini?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 font-bold" title="Hapus">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center text-slate-400">
                    <p class="font-semibold">Belum ada aktivitas bot hari ini.</p>
                </div>
                @endif
            </div>
        </div>

        <!-- ==================== TAB: REPORTS ==================== -->
        <div id="tab-reports" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-700">📊 Laporan dari Telegram (5 Terbaru)</h3>
                </div>

                @if($telegramReports->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr>
                                <th class="px-4 py-3">TG ID</th>
                                <th class="px-4 py-3">Tanggal</th>
                                <th class="px-4 py-3">Teknisi</th>
                                <th class="px-4 py-3">Alat</th>
                                <th class="px-4 py-3">Tindakan</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-center">Link</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($telegramReports as $report)
                            <tr>
                                <td class="px-4 py-3 text-xs font-mono text-blue-600">{{ $report->telegram_report_id ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ \Carbon\Carbon::parse($report->report_date)->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-xs font-semibold">{{ $report->employee?->name ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    @if($report->asset)
                                        <span class="text-xs font-bold text-blue-600">{{ $report->asset->tech_ident_no }}</span>
                                    @else
                                        <span class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold">⚠️ Unknown</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs max-w-xs truncate">{{ $report->action_taken }}</td>
                                <td class="px-4 py-3">
                                    @php $s = strtolower($report->status); @endphp
                                    <span class="text-[10px] font-bold px-2 py-1 rounded-full
                                        {{ $s == 'done' ? 'bg-green-100 text-green-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                        {{ $s }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <a href="{{ route('reports.index') }}" class="text-[10px] text-blue-600 hover:underline">🔍</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center text-slate-400">
                    <p class="font-semibold">Belum ada laporan dari Telegram.</p>
                </div>
                @endif

                <div class="px-6 py-3 border-t border-slate-100 text-right">
                    <a href="{{ route('reports.index') }}" class="text-xs text-blue-600 hover:underline font-bold">Lihat semua laporan →</a>
                </div>
            </div>
        </div>

        <!-- ==================== TAB: UNKNOWN ASSETS ==================== -->
        <div id="tab-unknown" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-700">⚠️ Equipment Tidak Dikenal (10 Terbaru)</h3>
                    <p class="text-xs text-slate-400 mt-1">Laporan ini masuk via Telegram tapi equipment tidak match dengan database. Mapping manual diperlukan.</p>
                </div>

                @if($unknownAssets->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr>
                                <th class="px-4 py-3">TG ID</th>
                                <th class="px-4 py-3">Teks Tindakan</th>
                                <th class="px-4 py-3">Teknisi</th>
                                <th class="px-4 py-3">Tanggal</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($unknownAssets as $report)
                            <tr>
                                <td class="px-4 py-3 text-xs font-mono text-blue-600">{{ $report->telegram_report_id ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs max-w-sm">{{ $report->action_taken }}</td>
                                <td class="px-4 py-3 text-xs">{{ $report->employee?->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs">{{ \Carbon\Carbon::parse($report->report_date)->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-center">
                                    <a href="{{ route('reports.index') }}" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 font-bold">
                                        ✏️ Mapping
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center">
                    <p class="text-4xl mb-2">🎉</p>
                    <p class="font-semibold text-slate-400">Semua equipment dikenal!</p>
                    <p class="text-xs text-slate-400 mt-1">Tidak ada laporan yang perlu mapping manual.</p>
                </div>
                @endif
            </div>
        </div>

        <!-- ==================== TAB: BLACKLIST ==================== -->
        <div id="tab-blacklist" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h3 class="text-lg font-bold text-slate-700 mb-6">🚫 Blacklist</h3>

                <!-- Form Block -->
                <form action="{{ route('telegram.block') }}" method="POST" class="mb-6 p-4 bg-red-50 rounded-lg border border-red-200">
                    @csrf
                    <h4 class="text-sm font-bold text-red-700 mb-3">Blokir User Baru</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input type="text" name="telegram_chat_id" placeholder="Chat ID" required
                            class="border-gray-300 rounded-lg p-2 border text-sm">
                        <input type="text" name="telegram_username" placeholder="Username (opsional)"
                            class="border-gray-300 rounded-lg p-2 border text-sm">
                        <input type="text" name="reason" placeholder="Alasan blokir"
                            class="border-gray-300 rounded-lg p-2 border text-sm">
                    </div>
                    <button type="submit" class="mt-3 bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-red-700">
                        🚫 Blokir
                    </button>
                </form>

                <!-- List Blacklist -->
                @if($blacklist->count() > 0)
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                        <tr>
                            <th class="px-4 py-3">Chat ID</th>
                            <th class="px-4 py-3">Username</th>
                            <th class="px-4 py-3">Alasan</th>
                            <th class="px-4 py-3">Diblokir oleh</th>
                            <th class="px-4 py-3">Waktu</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($blacklist as $item)
                        <tr>
                            <td class="px-4 py-3 text-xs font-mono">{{ $item->telegram_chat_id }}</td>
                            <td class="px-4 py-3 text-xs">{{ $item->telegram_username ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $item->reason ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $item->blockedBy?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $item->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-center">
                                <form action="{{ route('telegram.unblock', $item->id) }}" method="POST" onsubmit="return confirm('Unblock user ini?')">
                                    @csrf
                                    <button type="submit" class="text-xs bg-emerald-100 text-emerald-700 px-2 py-1 rounded hover:bg-emerald-200 font-bold">
                                        Unblock
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                <div class="p-6 text-center text-slate-400 text-sm">Belum ada user yang diblokir.</div>
                @endif
            </div>
        </div>

        <!-- ==================== TAB: REGISTRATION ==================== -->
        <div id="tab-registration" class="tab-content hidden">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200">
                    <h3 class="text-lg font-bold text-slate-700">📝 Pendaftaran Teknisi</h3>
                    <p class="text-xs text-slate-400 mt-1">User yang mengirim nomor HP untuk registrasi.</p>
                </div>

                @if($pendingRegistrations->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr>
                                <th class="px-4 py-3">Waktu</th>
                                <th class="px-4 py-3">Chat ID</th>
                                <th class="px-4 py-3">Username</th>
                                <th class="px-4 py-3">Nomor HP</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($pendingRegistrations as $reg)
                            <tr>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ $reg->created_at->format('H:i:s') }}</td>
                                <td class="px-4 py-3 text-xs font-mono">{{ $reg->telegram_chat_id }}</td>
                                <td class="px-4 py-3 text-xs">{{ $reg->telegram_username ?? '-' }}</td>
                                <td class="px-4 py-3 text-xs font-bold">{{ $reg->incoming_message }}</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex gap-1 justify-center">
                                        <form action="{{ route('telegram.approve', $reg->id) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="text-xs bg-emerald-100 text-emerald-700 px-2 py-1 rounded hover:bg-emerald-200 font-bold">
                                                ✅ Setujui
                                            </button>
                                        </form>
                                        <form action="{{ route('telegram.reject', $reg->id) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 font-bold">
                                                ❌ Tolak
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center text-slate-400">
                    <p class="font-semibold">Tidak ada pendaftaran pending.</p>
                </div>
                @endif
            </div>
        </div>

        <!-- FOOTER -->
        <div class="border-t border-slate-200 pt-6 mt-8">
            <div class="flex justify-between items-center">
                <p class="text-xs text-slate-400">&copy; {{ date('Y') }} Oleochemical Pro — Panel Kontrol Telegram Bot</p>
                <p class="text-xs text-slate-400">Powered by Erik Adam - IT Support</p>
            </div>
        </div>

    </main>

    <script>
        // ========== TAB SYSTEM ==========
        function showTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-white', 'text-slate-600', 'hover:bg-slate-50');
            });
            const activeBtn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
            if (activeBtn) {
                activeBtn.classList.remove('bg-white', 'text-slate-600', 'hover:bg-slate-50');
                activeBtn.classList.add('bg-blue-600', 'text-white');
            }

            // Show/hide content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            const activeContent = document.getElementById('tab-' + tabName);
            if (activeContent) {
                activeContent.classList.remove('hidden');
            }
        }

        // Auto-reload status every 30 seconds
        setInterval(function() {
            fetch('/telegram-control').then(r => r.text()).then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newIndicator = doc.getElementById('botStatusIndicator');
                const oldIndicator = document.getElementById('botStatusIndicator');
                if (newIndicator && oldIndicator) {
                    oldIndicator.outerHTML = newIndicator.outerHTML;
                }
            }).catch(() => {});
        }, 30000);
    </script>

</body>
</html>
