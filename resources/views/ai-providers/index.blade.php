<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel AI Providers - GAWI Oleochemical</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
        .status-dot.green { background-color: #22c55e; }
        .status-dot.yellow { background-color: #eab308; }
        .status-dot.orange { background-color: #f97316; }
        .status-dot.red { background-color: #ef4444; }
        .status-dot.gray { background-color: #9ca3af; }
        .card-hover { transition: all 0.2s ease; }
        .card-hover:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .progress-bar { transition: width 1s ease-in-out; }
        .log-entry:hover { background-color: #f8fafc; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-content { background: white; border-radius: 0.75rem; padding: 1.5rem; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">

    <!-- NAVBAR -->
    <header class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('dashboard') }}" class="text-blue-600 hover:underline font-medium text-sm">&larr; Dashboard</a>
                <span class="text-slate-300">|</span>
                <a href="{{ route('telegram.control') }}" class="text-blue-600 hover:underline font-medium text-sm">Bot Telegram</a>
                <span class="text-slate-300">|</span>
                <h1 class="text-lg font-bold text-slate-900">Panel AI Providers</h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-400 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded font-medium">Groq | Ollama | OpenAI</span>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">

        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 text-sm">{{ session('error') }}</div>
        @endif
        @if(session('info'))
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-6 text-sm whitespace-pre-line">{{ session('info') }}</div>
        @endif

        <!-- STATS CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">AI Provider</p>
                <p class="text-lg font-black text-blue-600">{{ count($providers) }}</p>
                <p class="text-[10px] text-slate-400">{{ count(array_filter($providers, fn($p) => $p['is_active'])) }} aktif</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Request (24 Jam)</p>
                <p class="text-lg font-black text-purple-600">{{ $stats24h['total_requests'] }}</p>
                <p class="text-[10px] text-slate-400">{{ $stats24h['success'] }} success | {{ $stats24h['failed'] }} failed</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Total Token</p>
                <p class="text-lg font-black text-emerald-600">{{ number_format($stats24h['total_tokens']) }}</p>
                <p class="text-[10px] text-slate-400">~${{ number_format($stats24h['total_cost'], 6) }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Sisa Harian</p>
                @php
                    $totalDailySisa = collect($providers)->sum('sisa_daily_tokens');
                    $totalDailyMax = collect($providers)->sum('max_daily_tokens');
                @endphp
                <p class="text-lg font-black text-amber-600">{{ number_format($totalDailySisa) }}</p>
                <p class="text-[10px] text-slate-400">dari {{ number_format($totalDailyMax) }} token/hari</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Fallback</p>
                <p class="text-lg font-black text-amber-600">{{ $stats24h['fallback_count'] }}</p>
                <p class="text-[10px] text-slate-400">kali pindah provider cadangan</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase text-slate-400 tracking-wider">Alias Dipelajari</p>
                <p class="text-lg font-black text-cyan-600">{{ $recentAliases->count() }}</p>
                <p class="text-[10px] text-slate-400">dari AI dan mapping user</p>
            </div>
        </div>

        <!-- AI PROVIDER CARDS -->
        <div class="mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-slate-700">Daftar AI Provider</h2>
                <div class="flex gap-2">
                    <button onclick="openModal('addProviderModal')" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700">+ Tambah Provider</button>
                    <form action="{{ route('ai-providers.test-all') }}" method="POST" class="inline" onsubmit="return confirm('Test semua provider?')">
                        @csrf
                        <button type="submit" class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-emerald-200">Test Semua</button>
                    </form>
                    <form action="{{ route('ai-providers.reset-quota') }}" method="POST" class="inline" onsubmit="return confirm('Reset quota bulanan & harian semua provider?')">
                        @csrf
                        <button type="submit" class="bg-amber-100 text-amber-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-amber-200">Reset Quota</button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @forelse($providers as $p)
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 card-hover relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-12 h-12">
                        <div class="absolute -top-3 -right-3 w-16 h-16 bg-slate-100 rotate-45"></div>
                        <span class="absolute top-1 right-4 text-xs font-bold text-slate-500">#{{ $p['priority'] }}</span>
                    </div>
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="font-bold text-slate-800 text-base">{{ $p['name'] }}</h3>
                            <p class="text-xs text-slate-400">{{ ucfirst($p['provider']) }} &middot; {{ $p['model'] }}</p>
                        </div>
                        <div class="text-right">
                            @php
                                $bgColor = match($p['status_color']) {
                                    'green' => 'bg-green-100 text-green-700',
                                    'yellow' => 'bg-yellow-100 text-yellow-700',
                                    'orange' => 'bg-orange-100 text-orange-700',
                                    'red' => 'bg-red-100 text-red-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-[10px] font-bold {{ $bgColor }}">
                                <span class="status-dot {{ $p['status_color'] }}"></span> {{ $p['status_label'] }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-[10px] font-medium px-2 py-0.5 rounded {{ $p['health_status'] === 'healthy' ? 'bg-green-100 text-green-700' : ($p['health_status'] === 'unhealthy' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                            @if($p['health_status'] === 'healthy') Sehat @elseif($p['health_status'] === 'unhealthy') Error @else Belum dicek @endif
                        </span>
                        @if(!$p['is_active']) <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded font-bold">Nonaktif</span> @endif
                        <span class="text-[10px] text-slate-400 ml-auto">Terakhir: {{ $p['last_used'] }}</span>
                    </div>
                    @if($p['persen_sisa'] !== null)
                    <div class="mb-3">
                        <div class="flex justify-between text-[10px] text-slate-500 mb-1">
                            <span>Sisa token bulanan</span>
                            <span class="font-bold">{{ number_format($p['sisa_tokens']) }}</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                            <div class="progress-bar h-2.5 rounded-full {{ $p['persen_sisa'] < 10 ? 'bg-red-500' : ($p['persen_sisa'] < 30 ? 'bg-yellow-500' : ($p['persen_sisa'] < 60 ? 'bg-blue-500' : 'bg-green-500')) }}"
                                style="width: {{ $p['persen_sisa'] }}%"></div>
                        </div>
                    </div>
                    @endif
                    @if($p['persen_daily_sisa'] !== null)
                    <div class="mb-3">
                        <div class="flex justify-between text-[10px] text-slate-500 mb-1">
                            <span>Sisa <span class="font-bold text-amber-600">HARIAN</span></span>
                            <span class="font-bold">{{ number_format($p['sisa_daily_tokens']) }}</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                            <div class="progress-bar h-2.5 rounded-full {{ $p['persen_daily_sisa'] < 10 ? 'bg-red-500' : ($p['persen_daily_sisa'] < 30 ? 'bg-yellow-500' : ($p['persen_daily_sisa'] < 60 ? 'bg-blue-500' : 'bg-green-500')) }}"
                                style="width: {{ $p['persen_daily_sisa'] }}%"></div>
                        </div>
                    </div>
                    @endif
                    <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                        <div class="bg-slate-50 rounded-lg p-2"><p class="text-xs font-bold text-slate-700">{{ number_format($p['total_requests']) }}</p><p class="text-[9px] text-slate-400">Request</p></div>
                        <div class="bg-slate-50 rounded-lg p-2"><p class="text-xs font-bold text-slate-700">{{ number_format($p['total_tokens']) }}</p><p class="text-[9px] text-slate-400">Total Token</p></div>
                        <div class="bg-slate-50 rounded-lg p-2"><p class="text-xs font-bold text-slate-700">{{ $p['last_used'] }}</p><p class="text-[9px] text-slate-400">Terakhir</p></div>
                    </div>
                    <div class="flex gap-2 pt-3 border-t border-slate-100">
                        <button onclick="openEditModal({{ json_encode($p) }})" class="flex-1 text-xs bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg hover:bg-blue-200 font-bold text-center">Edit</button>
                        <form action="{{ route('ai-providers.test', $p['id']) }}" method="POST" class="flex-1">
                            @csrf
                            <button type="submit" class="w-full text-xs bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-lg hover:bg-emerald-200 font-bold text-center">Test</button>
                        </form>
                        <form action="{{ route('ai-providers.destroy', $p['id']) }}" method="POST" class="flex-1" onsubmit="return confirm('Hapus provider {{ $p['name'] }}?')">
                            @csrf
                            <button type="submit" class="w-full text-xs bg-red-100 text-red-700 px-3 py-1.5 rounded-lg hover:bg-red-200 font-bold text-center">Hapus</button>
                        </form>
                    </div>
                </div>
                @empty
                <div class="col-span-2 text-center py-12 text-slate-400">
                    <p class="font-bold text-lg">--</p>
                    <p class="font-bold">Belum ada AI Provider</p>
                    <p class="text-xs mt-1">Tambahkan provider untuk mulai menggunakan AI</p>
                </div>
                @endforelse
            </div>
        </div>

        <!-- RECENT LOGS -->
        <div class="mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-slate-700">Riwayat Pemakaian (24 Jam)</h2>
                <form action="{{ route('ai-providers.clean-logs') }}" method="POST" onsubmit="return confirm('Hapus log pemakaian lebih dari 30 hari?')" class="inline">
                    @csrf
                    <button type="submit" class="text-xs bg-red-100 text-red-700 px-3 py-1.5 rounded-lg hover:bg-red-200 font-bold">Bersihkan Log</button>
                </form>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                @if($recentLogs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Provider</th><th class="px-4 py-3">Model</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Tokens</th><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Fallback Chain</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($recentLogs as $log)
                            <tr class="log-entry">
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-xs font-semibold">{{ $log->provider->name ?? 'N/A' }}</td>
                                <td class="px-4 py-3 text-[10px] text-slate-500">{{ $log->model_used }}</td>
                                <td class="px-4 py-3">
                                    @if($log->status === 'success') <span class="text-[10px] font-bold bg-green-100 text-green-700 px-2 py-1 rounded">Success</span>
                                    @elseif($log->status === 'fallback') <span class="text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-1 rounded">Fallback</span>
                                    @else <span class="text-[10px] font-bold bg-red-100 text-red-700 px-2 py-1 rounded" title="{{ $log->error_message }}">Failed</span> @endif
                                </td>
                                <td class="px-4 py-3 text-xs font-mono">{{ number_format($log->total_tokens) }}</td>
                                <td class="px-4 py-3 text-xs">{{ $log->processing_time_ms }}ms</td>
                                <td class="px-4 py-3 text-[10px] text-slate-400 max-w-[200px] truncate">{{ $log->fallback_chain }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center text-slate-400"><p class="font-semibold">Belum ada pemakaian AI dalam 24 jam terakhir.</p></div>
                @endif
            </div>
        </div>

        <!-- RECENT ALIASES -->
        <div class="mb-8">
            <h2 class="text-lg font-bold text-slate-700 mb-4">Alias yang Dipelajari AI</h2>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                @if($recentAliases->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                            <tr><th class="px-4 py-3">Alias</th><th class="px-4 py-3">Asset</th><th class="px-4 py-3">Teknisi</th><th class="px-4 py-3">Confidence</th><th class="px-4 py-3">Dipakai</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($recentAliases as $alias)
                            <tr>
                                <td class="px-4 py-3 text-xs font-bold text-blue-600">{{ $alias->alias }}</td>
                                <td class="px-4 py-3 text-xs">{{ $alias->asset->tech_ident_no ?? 'N/A' }}<br><span class="text-[9px] text-slate-400">{{ $alias->asset->description ?? '' }}</span></td>
                                <td class="px-4 py-3 text-xs">{{ $alias->employee->name ?? 'Global' }}</td>
                                <td class="px-4 py-3"><span class="text-xs font-bold {{ $alias->confidence_score >= 80 ? 'text-green-600' : ($alias->confidence_score >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $alias->confidence_score }}%</span></td>
                                <td class="px-4 py-3 text-xs">{{ $alias->usage_count }}x</td>
                                <td class="px-4 py-3">
                                    @if($alias->auto_generated)
                                        <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-1 rounded font-bold">AI</span>
                                    @else
                                        <span class="text-[10px] bg-purple-100 text-purple-700 px-2 py-1 rounded font-bold">User</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($alias->confirmed_by_admin)
                                        <span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded font-bold">Dikonfirmasi</span>
                                    @elseif($alias->is_rejected)
                                        <span class="text-[10px] bg-red-100 text-red-700 px-2 py-1 rounded font-bold" title="{{ $alias->rejection_reason }}">Ditolak</span>
                                    @else
                                        <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-1 rounded font-bold">Menunggu</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-1">
                                        @if(!$alias->confirmed_by_admin && !$alias->is_rejected)
                                            <form action="{{ route('ai-providers.confirm-alias', $alias->id) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded hover:bg-green-200 font-bold" title="Konfirmasi mapping ini">Konfirmasi</button>
                                            </form>
                                            <button onclick="showRejectForm({{ $alias->id }}, '{{ $alias->alias }}')" class="text-[10px] bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 font-bold" title="Tolak mapping ini">Tolak</button>
                                        @elseif($alias->confirmed_by_admin)
                                            <span class="text-[10px] text-green-600 font-bold">OK</span>
                                        @else
                                            <span class="text-[10px] text-red-600 font-bold">X</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-10 text-center text-slate-400"><p class="font-semibold">Belum ada alias yang dipelajari.</p><p class="text-xs mt-1">Alias akan otomatis dibuat saat AI menganalisa laporan.</p></div>
                @endif
            </div>
        </div>

        <!-- FOOTER -->
        <div class="border-t border-slate-200 pt-6 mt-6">
            <div class="flex justify-between items-center">
                <p class="text-xs text-slate-400">&copy; {{ date('Y') }} Oleochemical Pro &mdash; Panel AI Providers</p>
                <p class="text-xs text-slate-400">Powered by Groq Cloud &middot; Ollama Local</p>
            </div>
        </div>
    </main>

    <!-- MODAL TAMBAH PROVIDER -->
    <div id="addProviderModal" class="modal-overlay" onclick="if(event.target===this) closeModal('addProviderModal')">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-slate-700">Tambah AI Provider Baru</h3>
                <button onclick="closeModal('addProviderModal')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
            </div>
            <form action="{{ route('ai-providers.store') }}" method="POST">
                @csrf
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Nama Provider</label><input type="text" name="name" class="w-full border-gray-300 rounded-lg p-2 border text-sm" placeholder="Groq Primary" required></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Jenis</label>
                        <select name="provider" class="w-full border-gray-300 rounded-lg p-2 border text-sm" required>
                            <option value="groq">Groq (Cloud - Gratis)</option>
                            <option value="openai">OpenAI (Cloud - Berbayar)</option>
                            <option value="ollama">Ollama (Local - Gratis)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">API Key</label>
                    <div class="flex gap-2">
                        <input type="password" name="api_key" id="newApiKey" class="flex-1 border-gray-300 rounded-lg p-2 border text-sm font-mono" placeholder="gsk_..." required>
                        <button type="button" onclick="toggleKeyVisibility('newApiKey', this)" class="bg-slate-100 px-3 rounded-lg text-sm hover:bg-slate-200">Tampilkan</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Model</label>
                        <select name="model" class="w-full border-gray-300 rounded-lg p-2 border text-sm">
                            <optgroup label="Groq"><option value="llama-3.3-70b-versatile">Llama 3.3 70B (RECOMMENDED)</option><option value="llama-3.1-8b-instant">Llama 3.1 8B</option><option value="mixtral-8x7b-32768">Mixtral 8x7B</option><option value="gemma2-9b-it">Gemma 2 9B</option><option value="qwen/qwen3-32b">Qwen 3 32B</option></optgroup>
                            <optgroup label="OpenAI"><option value="gpt-4o-mini">GPT-4o Mini</option><option value="gpt-4o">GPT-4o</option></optgroup>
                            <optgroup label="Ollama"><option value="llama3.2">Llama 3.2</option><option value="mistral">Mistral</option></optgroup>
                        </select>
                    </div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Prioritas</label><input type="number" name="priority_order" class="w-full border-gray-300 rounded-lg p-2 border text-sm" value="1" min="1" max="10"></div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Max Token/Bulan</label><input type="number" name="max_monthly_tokens" class="w-full border-gray-300 rounded-lg p-2 border text-sm" placeholder="3000000" value="3000000"></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Max Token/Hari (TPD)</label><input type="number" name="max_daily_tokens" class="w-full border-gray-300 rounded-lg p-2 border text-sm" placeholder="100000" value="100000"></div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Rate Limit/menit</label><input type="number" name="requests_per_minute" class="w-full border-gray-300 rounded-lg p-2 border text-sm" value="30" min="1"></div>
                </div>
                <div class="mb-4"><label class="block text-xs font-medium text-gray-700 mb-1">Custom API URL (opsional)</label><input type="url" name="api_base_url" class="w-full border-gray-300 rounded-lg p-2 border text-sm" placeholder="https://api.groq.com/openai/v1"></div>
                <div class="mb-4"><label class="block text-xs font-medium text-gray-700 mb-1">Catatan</label><textarea name="notes" class="w-full border-gray-300 rounded-lg p-2 border text-sm" rows="2" placeholder="Token dari akun Groq..."></textarea></div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="closeModal('addProviderModal')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-slate-200">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700">Simpan Provider</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDIT PROVIDER -->
    <div id="editProviderModal" class="modal-overlay" onclick="if(event.target===this) closeModal('editProviderModal')">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-slate-700">Edit AI Provider</h3>
                <button onclick="closeModal('editProviderModal')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
            </div>
            <form id="editProviderForm" method="POST">
                @csrf
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Nama Provider</label><input type="text" name="name" id="edit_name" class="w-full border-gray-300 rounded-lg p-2 border text-sm" required></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Jenis</label>
                        <select name="provider" id="edit_provider" class="w-full border-gray-300 rounded-lg p-2 border text-sm" required>
                            <option value="groq">Groq (Cloud - Gratis)</option>
                            <option value="openai">OpenAI (Cloud - Berbayar)</option>
                            <option value="ollama">Ollama (Local - Gratis)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">API Key <span class="text-slate-400">(kosongkan jika tidak diubah)</span></label>
                    <div class="flex gap-2">
                        <input type="password" name="api_key" id="edit_api_key" class="flex-1 border-gray-300 rounded-lg p-2 border text-sm font-mono" placeholder="Biarkan kosong jika tidak diubah">
                        <button type="button" onclick="toggleKeyVisibility('edit_api_key', this)" class="bg-slate-100 px-3 rounded-lg text-sm hover:bg-slate-200">Tampilkan</button>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Model</label><select name="model" id="edit_model" class="w-full border-gray-300 rounded-lg p-2 border text-sm"></select></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Prioritas</label><input type="number" name="priority_order" id="edit_priority" class="w-full border-gray-300 rounded-lg p-2 border text-sm" min="1" max="10"></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                        <select name="is_active" id="edit_is_active" class="w-full border-gray-300 rounded-lg p-2 border text-sm">
                            <option value="1">Aktif</option>
                            <option value="0">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Max Token/Bulan</label><input type="number" name="max_monthly_tokens" id="edit_max_tokens" class="w-full border-gray-300 rounded-lg p-2 border text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Max Token/Hari (TPD)</label><input type="number" name="max_daily_tokens" id="edit_max_daily_tokens" class="w-full border-gray-300 rounded-lg p-2 border text-sm"></div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-xs font-medium text-gray-700 mb-1">Rate Limit/menit</label><input type="number" name="requests_per_minute" id="edit_rate_limit" class="w-full border-gray-300 rounded-lg p-2 border text-sm" min="1"></div>
                </div>
                <div class="mb-4"><label class="block text-xs font-medium text-gray-700 mb-1">API Base URL (opsional)</label><input type="url" name="api_base_url" id="edit_api_base_url" class="w-full border-gray-300 rounded-lg p-2 border text-sm"></div>
                <div class="mb-4"><label class="block text-xs font-medium text-gray-700 mb-1">Catatan</label><textarea name="notes" id="edit_notes" class="w-full border-gray-300 rounded-lg p-2 border text-sm" rows="2"></textarea></div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="closeModal('editProviderModal')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-slate-200">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL REJECT ALIAS -->
    <div id="rejectAliasModal" class="modal-overlay" onclick="if(event.target===this) closeModal('rejectAliasModal')">
        <div class="modal-content max-w-md">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-slate-700">Tolak Mapping AI</h3>
                <button onclick="closeModal('rejectAliasModal')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
            </div>
            <p class="text-sm text-slate-600 mb-4">Mapping untuk <b id="rejectAliasName">-</b> akan ditolak. AI akan belajar untuk tidak mengulanginya.</p>
            <form id="rejectAliasForm" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Alasan Penolakan</label>
                    <textarea name="reason" class="w-full border-gray-300 rounded-lg p-2 border text-sm" rows="3" placeholder="Misal: Nama alat tidak sesuai..."></textarea>
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="closeModal('rejectAliasModal')" class="bg-slate-100 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-slate-200">Batal</button>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-red-700">Tolak Mapping</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        function toggleKeyVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Sembunyikan'; }
            else { input.type = 'password'; btn.textContent = 'Tampilkan'; }
        }
        function openEditModal(provider) {
            document.getElementById('edit_name').value = provider.name;
            document.getElementById('edit_provider').value = provider.provider;
            document.getElementById('edit_priority').value = provider.priority;
            document.getElementById('edit_is_active').value = provider.is_active ? '1' : '0';
            document.getElementById('edit_max_tokens').value = provider.persen_sisa !== null ? (provider.sisa_tokens + provider.total_tokens) : '';
            document.getElementById('edit_max_daily_tokens').value = provider.persen_daily_sisa !== null ? (provider.sisa_daily_tokens + provider.current_daily_tokens) : '';
            document.getElementById('edit_rate_limit').value = 30;
            document.getElementById('edit_notes').value = '';
            const modelSelect = document.getElementById('edit_model');
            modelSelect.innerHTML = '';
            const models = {'groq': ['llama-3.3-70b-versatile','llama-3.1-8b-instant','mixtral-8x7b-32768','gemma2-9b-it','qwen/qwen3-32b'],'openai': ['gpt-4o-mini','gpt-4o'],'ollama': ['llama3.2','mistral']};
            (models[provider.provider] || models['groq']).forEach(m => {
                const opt = document.createElement('option');
                opt.value = m; opt.textContent = m;
                if (m === provider.model) opt.selected = true;
                modelSelect.appendChild(opt);
            });
            document.getElementById('editProviderForm').action = '{{ url("ai-providers") }}/' + provider.id + '/update';
            openModal('editProviderModal');
        }
        function showRejectForm(aliasId, aliasText) {
            document.getElementById('rejectAliasName').textContent = aliasText;
            document.getElementById('rejectAliasForm').action = '{{ url("ai-providers") }}/reject-alias/' + aliasId;
            openModal('rejectAliasModal');
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); });
    </script>
</body>
</html>
