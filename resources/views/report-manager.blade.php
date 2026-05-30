<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manajemen Laporan - GAWI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-800 font-sans">

    <div class="flex flex-col lg:flex-row min-h-screen">
        <!-- SIDEBAR: Collapse Tahun > Bulan > Tanggal -->
        <aside class="w-full lg:w-72 bg-white border-r border-slate-200 p-6 overflow-y-auto">
            <div class="mb-6">
                <a href="{{ route('dashboard') }}" class="text-blue-600 font-bold flex items-center gap-2 mb-4">
                    <span>&larr;</span> Dashboard Utama
                </a>
                <h1 class="text-xl font-black tracking-tight text-slate-900">MANAJEMEN<br><span class="text-blue-600">LAPORAN</span></h1>
                <p class="text-xs text-slate-400 mt-1">Tahun &gt; Bulan &gt; Tanggal</p>
                @if(request()->filled('filter_date_from') || request()->filled('filter_date_to') || request()->filled('filter_status') || request()->filled('filter_asset'))
                <div class="mt-2 flex items-center gap-1.5 text-[11px] text-amber-700 font-medium bg-amber-50 border border-amber-200 px-2.5 py-1.5 rounded-md">
                    <span>⏳</span>
                    <span>Filter aktif — sidebar menampilkan <strong>semua data</strong></span>
                </div>
                @endif
            </div>

            <!-- Search input untuk laporan -->
            <div class="mb-4">
                <input type="text" id="report_search" placeholder="🔍 Cari laporan..." oninput="filterReports(this.value)"
                    class="w-full border border-slate-200 rounded-lg p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>

            <!-- Collapse Tahun -->
            <div class="space-y-2" id="sidebar_years">
                @forelse($reports as $year => $months)
                    <details class="group year-group" {{ $loop->first ? 'open' : '' }}>
                        <summary class="flex items-center gap-2 p-3 bg-slate-50 rounded-lg cursor-pointer text-sm font-bold text-slate-700 list-none outline-none hover:bg-slate-100 transition-colors">
                            <span class="transform group-open:rotate-90 transition-transform text-xs">▶</span>
                            📅 {{ $year }}
                            <span class="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full ml-auto year-count">{{ collect($months)->flatten(2)->count() }}</span>
                        </summary>
                        <div class="pl-4 mt-2 space-y-1">
                            @foreach($months as $month => $dates)
                                <details class="group/month month-group">
                                    <summary class="flex items-center gap-2 p-2 rounded-lg cursor-pointer text-xs font-semibold text-slate-600 list-none outline-none hover:bg-blue-50 transition-colors">
                                        <span class="transform group-open/month:rotate-90 transition-transform text-[10px]">▶</span>
                                        📁 {{ $month }}
                                        <span class="text-[10px] bg-slate-200 px-2 py-0.5 rounded-full ml-auto month-count">{{ collect($dates)->flatten(1)->count() }}</span>
                                    </summary>
                                    <div class="pl-4 mt-1 space-y-1">
                                        @foreach($dates as $date => $dayReports)
                                            <button type="button" onclick="filterByDate('{{ $date }}')"
                                                class="flex items-center gap-2 w-full text-left p-2 rounded-lg text-xs text-slate-500 hover:bg-blue-50 transition-colors date-btn date-group"
                                                data-date="{{ $date }}">
                                                <span>📄</span>
                                                <span class="font-medium date-label">{{ date('d M Y', strtotime($date)) }}</span>
                                                <span class="text-[10px] bg-slate-100 px-2 py-0.5 rounded-full ml-auto date-count">{{ count($dayReports) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </details>
                @empty
                    <div class="p-6 text-center text-slate-400 text-xs">Belum ada laporan.</div>
                @endforelse
            </div>

            <div class="pt-6 border-t border-slate-100 text-center mt-6">
                <p class="text-[10px] text-slate-400 uppercase font-medium">Powered by Erik Adam - IT Support</p>
            </div>
        </aside>

        <!-- MAIN CONTENT: Tabel Laporan -->
        <main class="flex-1 p-6 lg:p-10 overflow-y-auto">
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h2 class="text-2xl font-bold">📋 Semua Laporan Perbaikan</h2>
                    <p class="text-slate-500 italic text-sm">
                        Total: <strong id="total_count">{{ $reportsPaginated->total() }}</strong> laporan
                        @if(count($reports) > 0)
                            · Halaman {{ $reportsPaginated->currentPage() }} dari {{ $reportsPaginated->lastPage() }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="openCreateModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm flex items-center gap-1">
                        <span>➕</span> Tambah Laporan
                    </button>
                    <a href="{{ route('maintenance.detail') }}" class="bg-white text-blue-600 border border-blue-200 px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-50 transition-colors shadow-sm">
                        📊 Maintenance Detail
                    </a>
                    <a href="{{ route('dashboard') }}" class="bg-slate-200 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold hover:bg-slate-300 transition-colors">
                        🏠 Dashboard
                    </a>
                </div>
            </div>

            <!-- Info filter aktif -->
            <div id="filter_badge" class="hidden mb-4 flex items-center gap-2">
                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-2">
                    📅 Filter: <span id="filter_label"></span>
                    <button onclick="clearFilter()" class="text-blue-500 hover:text-red-500 ml-1 font-bold">&times;</button>
                </span>
            </div>

            <!-- FILTER PANEL -->
            <div class="mb-4 bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <form action="{{ url()->current() }}" method="GET" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Dari Tanggal</label>
                        <input type="date" name="filter_date_from" value="{{ request('filter_date_from') }}"
                            class="border border-slate-200 rounded-lg p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none w-36">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Sampai Tanggal</label>
                        <input type="date" name="filter_date_to" value="{{ request('filter_date_to') }}"
                            class="border border-slate-200 rounded-lg p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none w-36">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                        <select name="filter_status" class="border border-slate-200 rounded-lg p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <option value="">Semua Status</option>
                            <option value="done" {{ request('filter_status') == 'done' ? 'selected' : '' }}>✅ Done</option>
                            <option value="continue" {{ request('filter_status') == 'continue' ? 'selected' : '' }}>🔄 Continue</option>
                            <option value="pending" {{ request('filter_status') == 'pending' ? 'selected' : '' }}>⏳ Pending</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Alat (Asset)</label>
                        <select name="filter_asset" class="border border-slate-200 rounded-lg p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none min-w-[180px]">
                            <option value="">Semua Alat</option>
                            @foreach($allAssets as $ast)
                                <option value="{{ $ast->id }}" {{ request('filter_asset') == $ast->id ? 'selected' : '' }}>{{ $ast->tech_ident_no }} - {{ Str::limit($ast->description, 30) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-blue-700 transition-colors">🔍 Filter</button>
                        @if(request()->anyFilled(['filter_date_from', 'filter_date_to', 'filter_status', 'filter_asset']))
                            <a href="{{ url()->current() }}" class="bg-slate-200 text-slate-600 px-4 py-2 rounded-lg text-xs font-bold hover:bg-slate-300 transition-colors">✕ Hapus</a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Tabel Laporan -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" id="reports_table">
                        <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase font-semibold tracking-normal">
                            <tr>
                                <th class="px-4 py-3">Tanggal</th>
                                <th class="px-4 py-3">Alat (Equipment)</th>
                                <th class="px-4 py-3">Teknisi</th>
                                <th class="px-4 py-3">Tindakan Perbaikan</th>
                                <th class="px-4 py-3">Shift</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-center">AI</th>
                                <th class="px-4 py-3 text-center">Dokumen</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" id="reports_tbody">
                            @php
                                $flatAll = collect($reports)->flatten(3);
                                $paginatedItems = $reportsPaginated ?? collect($flatAll->take(20));
                            @endphp
                            @forelse($paginatedItems as $report)
                            <tr class="hover:bg-blue-50/50 transition-colors report-row" data-date="{{ date('Y-m-d', strtotime($report->report_date)) }}">
                                <td class="px-4 py-3 font-medium whitespace-nowrap">{{ date('d M Y', strtotime($report->report_date)) }}</td>
                                <td class="px-4 py-3">
                                    <span class="text-blue-600 font-bold text-xs">{{ $report->asset->tech_ident_no ?? '-' }}</span>
                                    @if($report->asset && $report->asset->description)
                                        <span class="text-[10px] text-slate-400 block">{{ Str::limit($report->asset->description, 30) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs">{{ $report->employee->name ?? '-' }}</span>
                                    <span class="text-[10px] text-slate-400 block">{{ $report->employee->department ?? '' }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-600 text-xs max-w-xs">{{ $report->action_taken ?: $report->raw_text }}</td>
                                <td class="px-4 py-3">
                                    <span class="bg-slate-100 px-2 py-1 rounded text-[10px] font-bold">{{ $report->shift }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @php $s = strtolower($report->status); @endphp
                                    <span class="px-2 py-0.5 rounded-md text-[11px] font-medium uppercase whitespace-nowrap
                                        {{ $s == 'done' ? 'bg-emerald-100 text-emerald-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                        {{ $s }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @php
                                        $hasAiData = $report->ai_provider_used || $report->ai_notes || $report->ai_suggested || $report->ai_confidence !== null;

                                        // Build AI info for popup
                                        $aiBtnClass = 'text-slate-300';
                                        $aiBtnText = '-';
                                        if ($report->ai_suggested && $report->ai_confidence !== null && $report->ai_confidence >= 0.8) {
                                            $aiBtnClass = 'bg-sky-100 text-sky-700 hover:bg-sky-200';
                                            $aiBtnText = 'AI';
                                        } elseif ($report->ai_suggested && $report->ai_confidence !== null && $report->ai_confidence >= 0.5) {
                                            $aiBtnClass = 'bg-amber-100 text-amber-700 hover:bg-amber-200';
                                            $aiBtnText = 'AI';
                                        } elseif ($report->needs_admin_review || $report->ai_suggested) {
                                            $aiBtnClass = 'bg-red-100 text-red-700 hover:bg-red-200';
                                            $aiBtnText = 'Review';
                                        } elseif ($hasAiData) {
                                            $aiBtnClass = 'bg-slate-100 text-slate-600 hover:bg-slate-200';
                                            $aiBtnText = 'AI';
                                        }
                                    @endphp
                                    @if($hasAiData)
                                        <button onclick="showAiProcessModal({{ json_encode(['id' => $report->id, 'asset_code' => $report->asset->tech_ident_no ?? '-', 'asset_description' => $report->asset->description ?? '', 'employee_name' => $report->employee->name ?? '-', 'action_taken' => $report->action_taken ?: $report->raw_text, 'ai_provider' => $report->ai_provider_used ?? '-', 'ai_confidence' => $report->ai_confidence !== null ? round($report->ai_confidence * 100) : null, 'ai_suggested' => $report->ai_suggested, 'needs_review' => $report->needs_admin_review, 'ai_notes' => $report->ai_notes ?? '', 'ai_fallback_reason' => $report->ai_fallback_reason ?? '', 'shift' => $report->shift, 'status' => $report->status, 'report_date' => date('d/m/Y', strtotime($report->report_date))]) }})"
                                            class="text-[11px] px-2 py-0.5 rounded-md font-bold cursor-pointer transition-all hover:scale-105 {{ $aiBtnClass }}"
                                            title="Klik untuk lihat detail proses AI">
                                            {{ $aiBtnText }}
                                        </button>
                                    @else
                                        <span class="text-[10px] text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @php $docs = $report->documents ? json_decode($report->documents, true) : []; @endphp
                                    @if(count($docs) > 0)
                                        <button onclick="openDocModal('{{ addslashes(json_encode($docs)) }}', '{{ addslashes($report->asset->tech_ident_no ?? '') }}')" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 font-bold flex items-center gap-1 mx-auto">
                                            <span>📷</span> {{ count($docs) }}
                                        </button>
                                    @else
                                        <span class="text-[10px] text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex gap-1 justify-center">
                                        <button onclick="openEditModal({{ $report->id }})" class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded hover:bg-amber-200 font-bold">✏️</button>
                                        <form action="{{ route('maintenance-reports.destroy', $report->id) }}" method="POST" onsubmit="return confirm('Yakin hapus laporan ini?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 font-bold">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="p-10 text-center text-slate-400">Belum ada laporan perbaikan.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination Links -->
            <div class="px-4 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <span>Menampilkan</span>
                    <form action="{{ url()->current() }}" method="GET" class="inline-flex items-center gap-1">
                        @foreach(request()->except('per_page', 'page') as $key => $value)
                            @if($value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <select name="per_page" onchange="this.form.submit()" class="border border-slate-200 rounded p-1 text-xs font-bold text-slate-700">
                            <option value="20" {{ (request('per_page', 50) == 20) ? 'selected' : '' }}>20</option>
                            <option value="50" {{ (request('per_page', 50) == 50) ? 'selected' : '' }}>50</option>
                            <option value="100" {{ (request('per_page', 50) == 100) ? 'selected' : '' }}>100</option>
                            <option value="200" {{ (request('per_page', 50) == 200) ? 'selected' : '' }}>200</option>
                        </select>
                        <span>per halaman</span>
                        <noscript><button type="submit" class="text-blue-600 ml-1">Go</button></noscript>
                    </form>
                    <span class="ml-2">
                        @if($reportsPaginated->total() > 0)
                            {{ $reportsPaginated->firstItem() }} - {{ $reportsPaginated->lastItem() }} dari {{ $reportsPaginated->total() }}
                        @endif
                    </span>
                </div>
                <div class="flex items-center">
                    {{ $reportsPaginated->links() }}
                </div>
            </div>

            <!-- Ringkasan Footer -->
            <div class="mt-6 grid grid-cols-3 gap-4 text-center">
                @php
                    $allFlat = collect($reports)->flatten(3);
                    $doneCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'done')->count();
                    $pendingCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'pending')->count();
                    $continueCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'continue')->count();
                @endphp
                <div class="bg-emerald-50 p-3 rounded-lg border border-emerald-200">
                    <div class="text-2xl font-black text-emerald-600">{{ $doneCount }}</div>
                    <div class="text-[10px] text-emerald-700 font-bold uppercase">Done</div>
                </div>
                <div class="bg-amber-50 p-3 rounded-lg border border-amber-200">
                    <div class="text-2xl font-black text-amber-600">{{ $continueCount }}</div>
                    <div class="text-[10px] text-amber-700 font-bold uppercase">Continue</div>
                </div>
                <div class="bg-red-50 p-3 rounded-lg border border-red-200">
                    <div class="text-2xl font-black text-red-600">{{ $pendingCount }}</div>
                    <div class="text-[10px] text-red-700 font-bold uppercase">Pending</div>
                </div>
            </div>
        </main>
    </div>

    <!-- ==================== MODAL CREATE MAINTENANCE ==================== -->
    <div id="createReportModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto border border-slate-200">
            <button onclick="closeCreateModal()" class="absolute top-4 right-4 text-gray-500 hover:text-red-500 text-2xl font-bold">&times;</button>
            <h3 class="text-xl font-bold mb-4">➕ Tambah Laporan Perbaikan</h3>

            <form action="{{ route('maintenance-reports.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Aset/Alat <span class="text-red-500">*</span></label>
                        <div class="border border-gray-300 rounded-lg bg-white">
                            <input type="text" id="create_asset_search" placeholder="🔍 Cari asset..." oninput="filterCreateAssets(this.value)"
                                class="w-full border-b border-gray-300 rounded-t-lg p-2 text-sm focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <div id="create_asset_container" class="max-h-52 overflow-y-auto p-2 custom-scrollbar">
                                <div class="space-y-1" id="create_asset_list">
                                    @foreach($allAssets as $ast)
                                    <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition-colors hover:bg-blue-50 asset-item">
                                        <input type="radio" name="asset_id" value="{{ $ast->id }}" required
                                            class="w-4 h-4 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                        <span class="text-xs text-slate-600 font-medium asset-name">{{ $ast->tech_ident_no }}</span>
                                        <span class="text-[10px] text-slate-400 ml-auto truncate max-w-[120px] asset-desc">{{ Str::limit($ast->description, 20) }}</span>
                                    </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Teknisi <span class="text-red-500">*</span></label>
                        <select name="employee_id" id="create_employee_id" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Pilih Teknisi --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tindakan Perbaikan <span class="text-red-500">*</span></label>
                        <textarea name="action_taken" rows="3" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500"
                            oninput="onActionInput(this, 'create')"
                            placeholder="Deskripsi tindakan perbaikan... Misal: Pompa 1 bocor, ganti mechanical seal"></textarea>
                        <!-- AI Analysis Panel -->
                        <div id="ai_panel_create" class="hidden mt-2"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">📎 Dokumentasi (foto)</label>
                        <input type="file" name="documents[]" multiple accept="image/*"
                            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer border border-gray-300 rounded-lg p-1">
                        <p class="text-[10px] text-slate-400 mt-1">Format: JPG, PNG, GIF, WEBP. Maks 5MB per file. Bisa pilih banyak.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal <span class="text-red-500">*</span></label>
                        <input type="date" name="report_date" value="{{ date('Y-m-d') }}" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Shift</label>
                            <select name="shift" class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                                <option value="1">Shift 1 (Pagi)</option>
                                <option value="2">Shift 2 (Siang)</option>
                                <option value="3">Shift 3 (Malam)</option>
                                <option value="reguler">Reguler</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                                <option value="done">✅ Done</option>
                                <option value="continue">🔄 Continue</option>
                                <option value="pending">⏳ Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeCreateModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Simpan Laporan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== MODAL EDIT MAINTENANCE ==================== -->
    <div id="editReportModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto border border-slate-200">
            <button onclick="closeEditModal()" class="absolute top-4 right-4 text-gray-500 hover:text-red-500 text-2xl font-bold">&times;</button>
            <h3 class="text-xl font-bold mb-4">✏️ Edit Laporan Perbaikan</h3>

            <form id="editReportForm" method="POST" enctype="multipart/form-data">
                @csrf
                @method('POST')
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Aset/Alat <span class="text-red-500">*</span></label>
                        <div class="border border-gray-300 rounded-lg bg-white">
                            <input type="text" id="edit_asset_search" placeholder="🔍 Cari asset..." oninput="filterEditAssets(this.value)"
                                class="w-full border-b border-gray-300 rounded-t-lg p-2 text-sm focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <div id="edit_asset_container" class="max-h-52 overflow-y-auto p-2 custom-scrollbar">
                                <div class="space-y-1" id="edit_asset_list">
                                    @foreach($allAssets as $ast)
                                    <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition-colors hover:bg-blue-50 asset-item">
                                        <input type="radio" name="asset_id" value="{{ $ast->id }}" required
                                            class="w-4 h-4 text-blue-600 focus:ring-blue-500 cursor-pointer asset_radio">
                                        <span class="text-xs text-slate-600 font-medium asset-name">{{ $ast->tech_ident_no }}</span>
                                        <span class="text-[10px] text-slate-400 ml-auto truncate max-w-[120px] asset-desc">{{ Str::limit($ast->description, 20) }}</span>
                                    </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Teknisi <span class="text-red-500">*</span></label>
                        <select name="employee_id" id="edit_employee_id" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Pilih Teknisi --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tindakan Perbaikan <span class="text-red-500">*</span></label>
                        <textarea name="action_taken" id="edit_action_taken" rows="3" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500"
                            oninput="onActionInput(this, 'edit')"></textarea>
                        <!-- AI Analysis Panel -->
                        <div id="ai_panel_edit" class="hidden mt-2"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">📎 Dokumentasi</label>
                        <div id="edit_doc_preview" class="flex flex-wrap gap-2 mb-2"></div>
                        <input type="file" name="documents[]" multiple accept="image/*"
                            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer border border-gray-300 rounded-lg p-1">
                        <p class="text-[10px] text-slate-400 mt-1">Tambah foto baru (JPG, PNG, GIF, WEBP. Maks 5MB)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal <span class="text-red-500">*</span></label>
                        <input type="date" name="report_date" id="edit_report_date" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Shift</label>
                            <select name="shift" id="edit_shift" class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                                <option value="1">Shift 1 (Pagi)</option>
                                <option value="2">Shift 2 (Siang)</option>
                                <option value="3">Shift 3 (Malam)</option>
                                <option value="reguler">Reguler</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="edit_status" class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500">
                                <option value="done">✅ Done</option>
                                <option value="continue">🔄 Continue</option>
                                <option value="pending">⏳ Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeEditModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== MODAL VIEW DOKUMENTASI ==================== -->
    <div id="docViewModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-3xl p-6 relative max-h-[90vh] overflow-y-auto border border-slate-200">
            <button onclick="closeDocModal()" class="absolute top-4 right-4 text-gray-500 hover:text-red-500 text-2xl font-bold z-10">&times;</button>
            <h3 class="text-xl font-bold mb-4">📷 Dokumentasi Laporan</h3>
            <p class="text-sm text-slate-500 mb-4" id="doc_asset_label">Asset: -</p>
            <div id="doc_gallery" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Isi digenerate oleh JavaScript -->
            </div>
        </div>
    </div>

    <!-- ==================== MODAL PROSES AI LAPORAN ==================== -->
    <div id="aiProcessModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-3xl p-6 relative max-h-[90vh] overflow-y-auto border border-slate-200">
            <button onclick="closeAiProcessModal()" class="absolute top-4 right-4 text-gray-500 hover:text-red-500 text-2xl font-bold z-10">&times;</button>
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                <span>🧠</span> Proses AI: <span id="aiProcessTitle" class="text-blue-600">Laporan</span>
            </h3>
            <div id="aiProcessContent" class="space-y-3 text-sm">
                <p class="text-slate-400">Memuat data...</p>
            </div>
        </div>
    </div>

    <script>
        // === LOAD EMPLOYEES ===
        async function loadEmployees(selectId, selectedId = null) {
            try {
                const res = await fetch('/api/employees');
                const employees = await res.json();
                const select = document.getElementById(selectId);
                select.innerHTML = '<option value="">-- Pilih Teknisi --</option>';
                employees.forEach(emp => {
                    const sel = selectedId == emp.id ? 'selected' : '';
                    select.innerHTML += `<option value="${emp.id}" ${sel}>${emp.name} - ${emp.department || ''}</option>`;
                });
            } catch(e) {
                console.error('Gagal load employees:', e);
            }
        }

        // === MODAL CREATE ===
        function openCreateModal() {
            document.getElementById('createReportModal').classList.remove('hidden');
            document.getElementById('createReportModal').classList.add('flex');
            loadEmployees('create_employee_id');
        }
        function closeCreateModal() {
            document.getElementById('createReportModal').classList.add('hidden');
            document.getElementById('createReportModal').classList.remove('flex');
        }

        // === SEARCH / FILTER ASSETS (untuk modal create) ===
        function filterAssets(searchText, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const items = container.querySelectorAll('.asset-item');
            const q = searchText.toLowerCase().trim();
            items.forEach(item => {
                const name = item.querySelector('.asset-name')?.textContent?.toLowerCase() || '';
                const desc = item.querySelector('.asset-desc')?.textContent?.toLowerCase() || '';
                const match = q === '' || name.includes(q) || desc.includes(q);
                item.style.display = match ? '' : 'none';
            });
        }
        function filterCreateAssets(val) { filterAssets(val, 'create_asset_container'); }

        // === MODAL EDIT ===
        async function openEditModal(id) {
            const modal = document.getElementById('editReportModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            const res = await fetch(`/maintenance-reports/${id}/edit`);
            const data = await res.json();

            document.querySelectorAll('#edit_asset_list .asset_radio').forEach(r => {
                if (r.value == data.asset_id) r.checked = true;
            });
            document.getElementById('edit_action_taken').value = data.action_taken || '';
            document.getElementById('edit_report_date').value = data.report_date ? data.report_date.split(' ')[0] : '';
            document.getElementById('edit_shift').value = data.shift || '1';
            document.getElementById('edit_status').value = data.status || 'done';
            document.getElementById('editReportForm').action = `/maintenance-reports/${id}`;
            await loadEmployees('edit_employee_id', data.employee_id);

            // Tampilkan preview dokumen existing
            const preview = document.getElementById('edit_doc_preview');
            preview.innerHTML = '';
            let docs = [];
            if (data.documents) {
                try { docs = JSON.parse(data.documents); } catch(e) { docs = []; }
            }
            if (docs.length > 0) {
                docs.forEach(doc => {
                    const url = '/storage/' + doc;
                    const img = document.createElement('div');
                    img.className = 'relative group';
                    img.innerHTML = `
                        <img src="${url}" class="w-16 h-16 object-cover rounded border border-slate-200">
                        <span class="absolute -top-1 -right-1 bg-green-500 text-white text-[8px] w-4 h-4 rounded-full flex items-center justify-center font-bold">✓</span>
                    `;
                    preview.appendChild(img);
                });
            }
        }
        function closeEditModal() {
            document.getElementById('editReportModal').classList.add('hidden');
            document.getElementById('editReportModal').classList.remove('flex');
        }

        // Tutup modal jika klik backdrop
        document.querySelectorAll('.fixed.inset-0').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                    this.classList.remove('flex');
                }
            });
        });

        // Escape key untuk tutup modal AI
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(function(m) {
                    m.classList.add('hidden');
                    m.classList.remove('flex');
                });
            }
        });

        // === SEARCH / FILTER ASSETS (untuk modal edit) ===
        function filterAssets(searchText, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const items = container.querySelectorAll('.asset-item');
            const q = searchText.toLowerCase().trim();
            items.forEach(item => {
                const name = item.querySelector('.asset-name')?.textContent?.toLowerCase() || '';
                const desc = item.querySelector('.asset-desc')?.textContent?.toLowerCase() || '';
                const match = q === '' || name.includes(q) || desc.includes(q);
                item.style.display = match ? '' : 'none';
            });
        }
        function filterEditAssets(val) { filterAssets(val, 'edit_asset_container'); }

        // === FILTER LAPORAN berdasarkan tanggal (sidebar click) ===
        let activeDate = null;

        function filterByDate(date) {
            // Redirect ke halaman dengan filter tanggal
            const url = new URL(window.location.href);
            url.searchParams.set('filter_date_from', date);
            url.searchParams.set('filter_date_to', date);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        function clearFilter() {
            const url = new URL(window.location.href);
            url.searchParams.delete('filter_date_from');
            url.searchParams.delete('filter_date_to');
            url.searchParams.delete('filter_status');
            url.searchParams.delete('filter_asset');
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        // === SEARCH LAPORAN (sidebar quick search) ===
        let searchTimeout = null;

        function filterReports(val) {
            // Search menggunakan filter client-side karena tidak ada parameter search di URL
            // Tapi kita bisa highlight sidebar
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const q = val.toLowerCase().trim();
                const rows = document.querySelectorAll('.report-row');
                let totalVisible = 0;

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    const text = Array.from(cells).slice(0, 6).map(c => c.textContent.toLowerCase()).join(' ');
                    const match = q === '' || text.includes(q);
                    row.style.display = match ? '' : 'none';
                    if (match) totalVisible++;
                });

            document.getElementById('total_count').textContent = totalVisible;
            }, 300);
        }

        // === MODAL VIEW DOKUMENTASI ===
        function openDocModal(docsJson, assetName) {
            const modal = document.getElementById('docViewModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            document.getElementById('doc_asset_label').textContent = 'Asset: ' + (assetName || '-');

            const gallery = document.getElementById('doc_gallery');
            gallery.innerHTML = '';

            let docs = [];
            try { docs = JSON.parse(docsJson); } catch(e) { docs = []; }

            if (docs.length === 0) {
                gallery.innerHTML = '<div class="col-span-2 p-10 text-center text-slate-400">Tidak ada dokumentasi.</div>';
                return;
            }

            docs.forEach((doc, idx) => {
                const url = '/storage/' + doc;
                const div = document.createElement('div');
                div.className = 'bg-slate-50 rounded-lg overflow-hidden border border-slate-200';
                div.innerHTML = `
                    <a href="${url}" target="_blank" class="block">
                        <img src="${url}" alt="Dokumentasi ${idx+1}" class="w-full h-48 object-cover hover:opacity-90 transition-opacity">
                    </a>
                    <div class="p-2 flex justify-between items-center">
                        <span class="text-[10px] text-slate-500">Dokumen ${idx+1}</span>
                        <a href="${url}" target="_blank" class="text-[10px] text-blue-600 hover:text-blue-800 font-bold" download>⬇ Download</a>
                    </div>
                `;
                gallery.appendChild(div);
            });
        }
        function closeDocModal() {
            document.getElementById('docViewModal').classList.add('hidden');
            document.getElementById('docViewModal').classList.remove('flex');
        }

        // =============================================
        // === AI REAL-TIME ANALYSIS ===
        // =============================================
        let aiDebounceTimers = {};

        function onActionInput(inputEl, modalType) {
            clearTimeout(aiDebounceTimers[modalType]);
            aiDebounceTimers[modalType] = setTimeout(() => {
                analyzeWithAI(inputEl.value, modalType);
            }, 1200);
        }

        async function analyzeWithAI(text, modalType) {
            if (!text || text.trim().length < 5) {
                hideAiPanel(modalType);
                return;
            }

            const employeeSelect = document.getElementById(modalType === 'create' ? 'create_employee_id' : 'edit_employee_id');
            const employeeId = employeeSelect?.value;
            const shiftEl = document.getElementById(modalType === 'create' ? '' : 'edit_shift');
            const shift = shiftEl?.value || '1';
            const dateEl = document.getElementById(modalType === 'create' ? '' : 'edit_report_date');
            const date = dateEl?.value || new Date().toISOString().split('T')[0];

            if (!employeeId) {
                showAiPanel(modalType, 'warning', '⚠️ Pilih teknisi dulu agar AI bisa menganalisa');
                return;
            }

            showAiPanel(modalType, 'loading', '🧠 AI sedang menganalisa laporan...');

            try {
                const res = await fetch('{{ route("ai.analyze-report") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        raw_text: text,
                        employee_id: parseInt(employeeId),
                        shift: shift,
                        report_date: date
                    })
                });

                const result = await res.json();

                if (result.success) {
                    const suggestions = result.items || [];
                    const warnings = result.warnings || [];
                    const unknowns = result.unknown_assets || [];
                    const newAliases = result.new_aliases || [];

                    let html = '';

                    // Summary
                    if (result.summary) {
                        html += `<div class="text-sm font-bold text-slate-700 mb-2">📋 ${result.summary}</div>`;
                    }

                    // Warnings
                    for (const w of warnings) {
                        const icon = w.includes('tidak ditemukan') ? '❌' : w.includes('mirip') ? '⚠️' : '💡';
                        html += `<div class="flex items-start gap-2 text-xs p-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 mb-1">
                            <span>${icon}</span>
                            <span>${w}</span>
                        </div>`;
                    }

                    // Unknown assets
                    for (const u of unknowns) {
                        html += `<div class="flex items-start gap-2 text-xs p-2 rounded-lg bg-red-50 border border-red-200 text-red-700 mb-1">
                            <span>❓</span>
                            <div>
                                <span class="font-bold">"${u.text || u}"</span>
                                ${u.reason ? `<br><span class="text-red-500">${u.reason}</span>` : ''}
                            </div>
                        </div>`;
                    }

                    // Suggested asset
                    if (suggestions.length > 0 && suggestions[0].suggested_asset_id) {
                        const item = suggestions[0];
                        const conf = Math.round((item.confidence || 0) * 100);
                        const color = conf >= 80 ? 'green' : (conf >= 50 ? 'amber' : 'red');

                        html += `<div class="flex items-center justify-between p-2 rounded-lg bg-${color}-50 border border-${color}-200 mt-1">
                            <div class="flex items-center gap-2">
                                <span>${conf >= 80 ? '✅' : '💡'}</span>
                                <div>
                                    <span class="text-xs font-bold text-slate-700">Saran AI:</span>
                                    <span class="text-xs text-blue-600 font-bold ml-1">${item.suggested_tech_ident_no || 'ID #' + item.suggested_asset_id}</span>
                                    <span class="text-xs text-slate-400 ml-2">confidence: ${conf}%</span>
                                </div>
                            </div>
                            <button onclick="applyAiSuggestion('${modalType}', ${item.suggested_asset_id})"
                                class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded font-bold hover:bg-blue-200">
                                Pakai
                            </button>
                        </div>`;
                    }

                    // New aliases info
                    if (newAliases.length > 0) {
                        html += `<div class="text-[10px] text-slate-400 mt-1">🧠 Mempelajari ${newAliases.length} alias baru dari teks ini</div>`;
                    }

                    if (!html) {
                        html = '<div class="text-xs text-green-600 p-2 bg-green-50 rounded-lg border border-green-200">✅ AI mengenali semua item dalam laporan ini</div>';
                    }

                    showAiPanel(modalType, 'result', html);
                } else {
                    showAiPanel(modalType, 'warning', '⚠️ ' + (result.error || 'AI tidak bisa menganalisa'));
                }
            } catch (e) {
                showAiPanel(modalType, 'error', '❌ Gagal: ' + e.message);
            }
        }

        function showAiPanel(modalType, type, content) {
            const panel = document.getElementById('ai_panel_' + modalType);
            if (!panel) return;
            panel.classList.remove('hidden');

            if (type === 'loading') {
                panel.innerHTML = `<div class="flex items-center gap-2 p-3 rounded-lg bg-blue-50 border border-blue-200">
                    <div class="animate-spin w-4 h-4 border-2 border-slate-200 border-t-blue-600 rounded-full"></div>
                    <span class="text-xs text-blue-700">${content}</span>
                </div>`;
            } else if (type === 'warning' || type === 'error') {
                const bg = type === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-800';
                panel.innerHTML = `<div class="p-3 rounded-lg ${bg} border text-sm">${content}</div>`;
            } else if (type === 'result') {
                panel.innerHTML = `<div class="p-3 rounded-lg bg-slate-50 border border-slate-200">${content}</div>`;
            }
        }

        function hideAiPanel(modalType) {
            const panel = document.getElementById('ai_panel_' + modalType);
            if (panel) panel.classList.add('hidden');
        }

        function applyAiSuggestion(modalType, assetId) {
            const container = document.getElementById(modalType === 'create' ? 'create_asset_container' : 'edit_asset_container');
            if (!container) return;
            const radio = container.querySelector('input[type="radio"][value="' + assetId + '"]');
            if (radio) {
                radio.checked = true;
                const item = radio.closest('.asset-item');
                if (item) {
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    item.classList.add('ring-2', 'ring-blue-400', 'bg-blue-50');
                    setTimeout(() => item.classList.remove('ring-2', 'ring-blue-400'), 2000);
                }
            }
        }

        // Ketika user klik label asset di daftar, langsung scroll ke item
        document.querySelectorAll('#create_asset_container, #edit_asset_container').forEach(function(cont) {
            cont.addEventListener('click', function(e) {
                const label = e.target.closest('.asset-item');
                if (label) {
                    const radio = label.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                }
            });
        });

        // =============================================
        // === AI PROCESS MODAL (POPUP AUDIT) ===
        // =============================================
        const reportAuditData = @json($reportAuditJson ?? []);

        function showAiProcessModal(aiInfo) {
            const modal = document.getElementById('aiProcessModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            document.getElementById('aiProcessTitle').textContent = 'Laporan #' + aiInfo.id + ' - ' + (aiInfo.asset_code || '');

            let html = '';

            // ===== BAGIAN 1: Data Laporan =====
            html += '<div class="bg-blue-50 border border-blue-200 rounded-lg p-3">';
            html += '<p class="text-[10px] font-bold uppercase text-blue-600 mb-1">📝 Teks Laporan / Tindakan</p>';
            html += '<p class="text-sm font-mono bg-white rounded p-2 border border-blue-100">' + (aiInfo.action_taken || '-') + '</p>';
            html += '</div>';

            // Grid info utama
            const conf = aiInfo.ai_confidence;
            let confColor = 'text-slate-600';
            if (conf !== null) {
                confColor = conf >= 80 ? 'text-green-600' : (conf >= 50 ? 'text-amber-600' : 'text-red-600');
            }
            let statusBadge = '';
            if (aiInfo.needs_review) { statusBadge = '<span class="text-[11px] bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold">Perlu Review</span>'; }
            else if (aiInfo.ai_suggested && conf !== null && conf >= 80) { statusBadge = '<span class="text-[11px] bg-green-100 text-green-700 px-2 py-0.5 rounded font-bold">Disarankan AI ✓</span>'; }
            else if (aiInfo.ai_suggested) { statusBadge = '<span class="text-[11px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded font-bold">Saran AI</span>'; }
            else { statusBadge = '<span class="text-[11px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-bold">Manual</span>'; }

            html += '<div class="grid grid-cols-2 md:grid-cols-4 gap-2">';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Asset</p><p class="text-sm font-bold text-blue-600">' + (aiInfo.asset_code || '-') + '</p><p class="text-[10px] text-slate-500 truncate">' + (aiInfo.asset_description || '') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Confidence AI</p><p class="text-lg font-black ' + confColor + '">' + (conf !== null ? conf + '%' : 'N/A') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">AI Provider</p><p class="text-sm font-bold break-all">' + (aiInfo.ai_provider || '-') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Status AI</p><p>' + statusBadge + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Tanggal</p><p class="text-sm font-bold">' + (aiInfo.report_date || '-') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Shift</p><p class="text-sm font-bold">' + (aiInfo.shift || '-') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Status Laporan</p><p class="text-sm font-bold">' + (aiInfo.status || '-') + '</p></div>';
            html += '<div class="bg-white border border-slate-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-slate-400">Teknisi</p><p class="text-sm font-bold">' + (aiInfo.employee_name || '-') + '</p></div>';
            html += '</div>';

            // ===== BAGIAN 2: Catatan AI =====
            if (aiInfo.ai_notes) {
                html += '<div class="bg-slate-100 border border-slate-200 rounded-lg p-3">';
                html += '<p class="text-[10px] font-bold uppercase text-slate-500 mb-1">📋 Catatan AI</p>';
                html += '<p class="text-sm text-slate-700">' + aiInfo.ai_notes + '</p>';
                html += '</div>';
            }

            if (aiInfo.ai_fallback_reason) {
                html += '<div class="bg-amber-50 border border-amber-200 rounded-lg p-3">';
                html += '<p class="text-[10px] font-bold uppercase text-amber-600 mb-1">⚠️ Fallback Reason</p>';
                html += '<p class="text-sm text-amber-700">' + aiInfo.ai_fallback_reason + '</p>';
                html += '</div>';
            }

            // ===== BAGIAN 3: Data Audit Log (jika ada yang cocok) =====
            // Cari audit log yang cocok berdasarkan teks
            const matchedAudit = reportAuditData.find(function(a) {
                return a.original_text && aiInfo.action_taken &&
                       (a.original_text.trim().toLowerCase() === aiInfo.action_taken.trim().toLowerCase() ||
                        a.original_text.trim().toLowerCase().includes(aiInfo.action_taken.trim().toLowerCase()) ||
                        aiInfo.action_taken.trim().toLowerCase().includes(a.original_text.trim().toLowerCase()));
            });

            if (matchedAudit) {
                html += '<div class="border-t border-slate-200 pt-2">';
                html += '<p class="text-[11px] font-bold uppercase text-purple-600 mb-2">🔍 Detail Proses AI (Audit Log)</p>';

                // Area
                html += '<div class="grid grid-cols-2 md:grid-cols-4 gap-2">';
                html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-purple-500">Area Dari Teks</p><p class="text-sm font-bold">' + (matchedAudit.area_detected || 'Tidak ada') + '</p></div>';
                html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-purple-500">Area Asset</p><p class="text-sm font-bold">' + (matchedAudit.area_asset || 'Tidak diketahui') + '</p></div>';
                html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-purple-500">Area Cocok?</p><p class="text-sm font-bold ' + (matchedAudit.area_match ? 'text-green-600' : 'text-red-600') + '">' + (matchedAudit.area_match ? '✅ YA' : '❌ TIDAK') + '</p></div>';
                html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-3"><p class="text-[9px] font-bold uppercase text-purple-500">Waktu / Oleh</p><p class="text-sm font-bold">' + (matchedAudit.telegram_username || matchedAudit.employee_name || '-') + '</p><p class="text-[10px] text-slate-400">' + (matchedAudit.occurred_at || '') + '</p></div>';
                html += '</div>';

                // Keywords
                if (matchedAudit.keywords_used && matchedAudit.keywords_used.length > 0) {
                    html += '<div class="bg-amber-50 border border-amber-200 rounded-lg p-3">';
                    html += '<p class="text-[10px] font-bold uppercase text-amber-600 mb-1">🏷️ Keyword yang Diekstrak</p>';
                    html += '<div class="flex flex-wrap gap-1">';
                    matchedAudit.keywords_used.forEach(function(kw) {
                        html += '<span class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded font-mono border border-amber-300">' + kw + '</span>';
                    });
                    html += '</div></div>';
                }

                // Opsi AI
                if (matchedAudit.ai_possible_assets && matchedAudit.ai_possible_assets.length > 0) {
                    html += '<div class="bg-purple-50 border border-purple-200 rounded-lg p-3">';
                    html += '<p class="text-[10px] font-bold uppercase text-purple-600 mb-1">🎯 Opsi yang Dipertimbangkan AI</p>';
                    html += '<div class="space-y-1.5">';
                    matchedAudit.ai_possible_assets.forEach(function(opt, i) {
                        var letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
                        var letter = letters[i] || '?';
                        var pct = Math.round((opt.confidence || 0) * 100);
                        html += '<div class="flex items-center justify-between bg-white rounded p-2 border border-purple-100 hover:bg-purple-50/50">';
                        html += '<div class="flex items-center gap-2"><span class="font-bold text-purple-700 text-xs bg-purple-100 w-5 h-5 rounded-full flex items-center justify-center">' + letter + '</span> ';
                        html += '<span class="text-xs font-mono font-bold">' + (opt.tech_ident_no || '-') + '</span>';
                        html += '<span class="text-[10px] text-slate-500">' + (opt.description || '') + '</span>';
                        if (opt.location) html += ' <span class="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded text-slate-500">' + opt.location + '</span>';
                        html += '</div>';
                        html += '<span class="text-xs font-bold px-2 py-0.5 rounded ' + (pct >= 80 ? 'bg-green-100 text-green-700' : (pct >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700')) + '">' + pct + '%</span>';
                        html += '</div>';
                    });
                    html += '</div></div>';
                }

                // AI Reasoning
                if (matchedAudit.ai_reasoning) {
                    html += '<div class="bg-slate-100 border border-slate-200 rounded-lg p-3">';
                    html += '<p class="text-[10px] font-bold uppercase text-slate-500 mb-1">💡 Alasan AI</p>';
                    html += '<p class="text-sm text-slate-600 italic bg-white rounded p-2 border border-slate-200">"' + matchedAudit.ai_reasoning + '"</p>';
                    html += '</div>';
                }

                html += '</div>';
            } else if (aiInfo.ai_notes || aiInfo.ai_provider !== '-' || conf !== null) {
                // Jika tidak ada audit log tapi ada data AI, tampilkan info bahwa tidak ada audit detail
                html += '<div class="bg-slate-50 border border-slate-200 rounded-lg p-3">';
                html += '<p class="text-[10px] font-bold uppercase text-slate-400 mb-1">ℹ️ Informasi</p>';
                html += '<p class="text-xs text-slate-500">Data ini berasal dari laporan yang diproses AI. Detail lengkap proses AI (keyword, area, opsi) hanya tersedia jika laporan dibuat melalui Telegram Bot dengan fitur audit log aktif.</p>';
                html += '</div>';
            }

            document.getElementById('aiProcessContent').innerHTML = html;
        }

        function closeAiProcessModal() {
            document.getElementById('aiProcessModal').classList.add('hidden');
            document.getElementById('aiProcessModal').classList.remove('flex');
        }
    </script>
</body>
</html>
