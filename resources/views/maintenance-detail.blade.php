<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sistem Monitoring Maintenance - GAWI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-800 font-sans">

    <div class="flex flex-col lg:flex-row min-h-screen">
        <!-- SIDEBAR -->
        <aside class="w-full lg:w-80 bg-white border-r border-slate-200 p-6">
            <div class="mb-8">
                <a href="{{ route('dashboard') }}" class="text-blue-600 font-bold flex items-center gap-2 mb-4">
                    <span>&larr;</span> Dashboard Utama
                </a>
                <h1 class="text-xl font-black tracking-tight text-slate-900">MAINTENANCE<br><span class="text-blue-600">REPORT</span></h1>
            </div>

            <form action="{{ route('maintenance.detail') }}" method="GET" class="space-y-6" id="filterForm">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">
                        Pilih 1-2 Alat (centang untuk bandingkan)
                    </label>

                    <div class="border border-slate-200 rounded-lg bg-white shadow-sm overflow-hidden">
                        <details class="group" {{ count($selectedIds) > 0 ? 'open' : '' }}>
                            <summary class="flex justify-between items-center p-3 cursor-pointer bg-slate-50 hover:bg-slate-100 text-sm font-bold text-slate-700 list-none outline-none">
                                <span class="truncate pr-2 text-blue-700">
                                    @if(count($selectedAssets) > 0)
                                        {{ $selectedAssets->pluck('tech_ident_no')->implode(' vs ') }}
                                    @else
                                        -- Semua Aset --
                                    @endif
                                </span>
                                <span class="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full mr-2">{{ count($selectedIds) }}/2</span>
                                <span class="transform group-open:rotate-180 transition-transform text-xs">▼</span>
                            </summary>

                            <div class="border-t border-slate-200">
                                <input type="text" id="sidebar_asset_search" placeholder="🔍 Cari asset..." oninput="filterSidebarAssets(this.value)"
                                    class="w-full border-b border-slate-200 p-2 text-xs focus:ring-blue-500 focus:border-blue-500 outline-none">

                                <div class="max-h-60 overflow-y-auto p-2 custom-scrollbar" id="sidebar_asset_list">
                                    @if(count($selectedIds) > 0)
                                    <a href="{{ route('maintenance.detail') }}?period={{ $currentPeriod }}" class="flex items-center gap-3 p-2 hover:bg-red-50 rounded-lg mb-2 border border-dashed border-red-200 text-red-600 text-xs font-bold transition-colors">
                                        ✕ Reset pilihan aset
                                    </a>
                                    @endif

                                    @foreach($allAssets->groupBy('object_type') as $type => $assets)
                                        <details class="group/sub mb-1 sidebar-asset-group">
                                            <summary class="flex items-center gap-2 p-2 hover:bg-slate-50 rounded-lg cursor-pointer text-xs font-bold text-slate-600 list-none outline-none">
                                                <span class="transform group-open/sub:rotate-90 transition-transform text-[10px]">▶</span>
                                                <span class="group-title">{{ $type ?: 'Tipe Lainnya' }}</span>
                                                <span class="text-[10px] bg-slate-200 px-2 py-0.5 rounded-full ml-auto group-count">{{ count($assets) }}</span>
                                            </summary>

                                            <div class="pl-6 pr-2 py-1 space-y-1">
                                                @foreach($assets as $ast)
                                                <label class="flex items-center gap-3 p-2 rounded-lg cursor-pointer transition-colors asset-item
                                                    {{ in_array($ast->id, $selectedIds) ? 'bg-blue-100 border border-blue-300' : 'hover:bg-blue-50 border border-transparent' }}">
                                                    <input type="checkbox" name="asset_ids[]" value="{{ $ast->id }}"
                                                        onchange="limitCheckbox(this)"
                                                        class="w-4 h-4 text-blue-600 focus:ring-blue-500 cursor-pointer rounded"
                                                        {{ in_array($ast->id, $selectedIds) ? 'checked' : '' }}>
                                                    <span class="text-xs text-slate-600 font-medium asset-name">{{ $ast->tech_ident_no }}</span>
                                                </label>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="submit" class="bg-blue-600 text-white text-xs font-bold py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            🔄 Terapkan
                        </button>
                        <a href="{{ route('maintenance.detail') }}?period={{ $currentPeriod }}" class="bg-slate-200 text-slate-600 text-xs font-bold py-2 rounded-lg text-center hover:bg-slate-300 transition-colors">
                            ✕ Reset
                        </a>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Periode Laporan</label>
                    <div class="flex flex-col gap-2">
                        <button type="submit" name="period" value="weekly" class="text-left px-4 py-2 rounded-lg text-sm {{ $currentPeriod == 'weekly' ? 'bg-blue-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200' }}">📅 Mingguan (Minggu Ini)</button>
                        <button type="submit" name="period" value="monthly" class="text-left px-4 py-2 rounded-lg text-sm {{ $currentPeriod == 'monthly' ? 'bg-blue-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200' }}">📊 Bulanan (Per Bulan)</button>
                        <button type="submit" name="period" value="yearly" class="text-left px-4 py-2 rounded-lg text-sm {{ $currentPeriod == 'yearly' ? 'bg-blue-600 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200' }}">📈 Tahunan (Historical)</button>
                    </div>
                </div>

                <div class="pt-6 border-t border-slate-100 text-center">
                    <p class="text-[10px] text-slate-400 uppercase font-medium">Powered by Erik Adam - IT Support</p>
                </div>
            </form>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="flex-1 p-6 lg:p-10 overflow-y-auto">
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h2 class="text-2xl font-bold">Ringkasan Aktivitas</h2>
                    <p class="text-slate-500 italic">
                        @if(count($selectedAssets) > 0)
                            Perbandingan: <strong>{{ $selectedAssets->pluck('tech_ident_no')->implode(' vs ') }}</strong>
                        @else
                            Menampilkan data perbaikan seluruh aset pabrik.
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="openCreateModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm flex items-center gap-1">
                        <span>➕</span> Tambah Laporan
                    </button>
                    <a href="{{ route('reports.index') }}" class="bg-white text-blue-600 border border-blue-200 px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-50 transition-colors shadow-sm flex items-center gap-1">
                        <span>📋</span> Manajemen Laporan
                    </a>
                    <div class="bg-blue-50 text-blue-700 px-4 py-2 rounded-lg font-bold border border-blue-100">
                        {{ strtoupper($currentPeriod) }} - {{ $currentYear }}
                    </div>
                </div>
            </div>

            <!-- GRAFIK PERBANDINGAN (Multi-Dataset) -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-10">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-slate-700">Grafik Frekuensi Perbaikan</h3>
                    <div class="flex gap-3 text-xs" id="chartLegend"></div>
                </div>
                <div style="height: 300px;">
                    <canvas id="mainChart"></canvas>
                </div>
            </div>

            <!-- TABEL SPLIT (Jika 2 alat dipilih) -->
            <div class="space-y-4">
                <h3 class="font-bold text-lg text-slate-700 mb-4">Log Perbaikan Terperinci</h3>

                @forelse($allReports as $year => $months)
                    <div class="text-xs font-black text-slate-400 mb-2 mt-6 uppercase tracking-[0.2em]">{{ $year }}</div>
                    @foreach($months as $month => $assetGroups)
                        <details class="group bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm transition-all" open>
                            <summary class="flex justify-between items-center p-4 cursor-pointer list-none hover:bg-slate-50">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center">
                                        <span class="transform group-open:rotate-90 transition-transform font-bold text-xs">▶</span>
                                    </div>
                                    <span class="font-bold text-slate-700 uppercase tracking-tight">{{ $month }}</span>
                                </div>
                                @php
                                    $totalCount = collect($assetGroups)->flatten(1)->count();
                                @endphp
                                <span class="text-xs font-bold px-3 py-1 bg-slate-100 rounded-full">{{ $totalCount }} Pekerjaan</span>
                            </summary>

                            <div class="p-0 border-t border-slate-100">
                                @if(count($selectedAssets) > 0)
                                    {{-- MODE SPLIT: Buat grid 2 kolom --}}
                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-0 lg:divide-x divide-slate-200">
                                        @foreach($selectedAssets as $ast)
                                        <div>
                                            <div class="px-4 py-2 bg-blue-50/50 border-b border-slate-100">
                                                <span class="text-xs font-black text-blue-700 uppercase tracking-wider">{{ $ast->tech_ident_no }}</span>
                                                <span class="text-[10px] text-slate-400 ml-2">- {{ $ast->description }}</span>
                                            </div>
                                            @php
                                                $items = $assetGroups[$ast->id] ?? collect();
                                            @endphp
                                            @if($items->count() > 0)
                                            <table class="w-full text-left text-sm">
                                                <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                                                    <tr>
                                                        <th class="px-4 py-3">Tanggal</th>
                                                        <th class="px-4 py-3">Tindakan</th>
                                                        <th class="px-4 py-3">Shift</th>
                                                        <th class="px-4 py-3">Status</th>
                                                        <th class="px-4 py-3 text-center">Doc</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach($items as $report)
                                                    <tr class="hover:bg-blue-50/50">
                                                        <td class="px-4 py-3 font-medium text-xs">{{ date('d M', strtotime($report->report_date)) }}</td>
                                                        <td class="px-4 py-3 text-slate-600 text-xs">{{ $report->action_taken ?: $report->raw_text }}</td>
                                                        <td class="px-4 py-3">
                                                            <span class="bg-slate-100 px-2 py-1 rounded text-[10px] font-bold">{{ $report->shift }}</span>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            @php $s = strtolower($report->status); @endphp
                                                            <span class="px-2 py-1 rounded-full text-[10px] font-black uppercase
                                                                {{ $s == 'done' ? 'bg-green-100 text-green-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                                                {{ $s }}
                                                            </span>
                                                        </td>
                                                        <td class="px-4 py-3 text-center">
                                                            @php $docs = $report->documents ? json_decode($report->documents, true) : []; @endphp
                                                            @if(count($docs) > 0)
                                                                <button onclick="openDocModal({{ $report->id }}, '{{ addslashes($report->asset?->tech_ident_no ?? 'Unknown') }}')" class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded hover:bg-blue-200 font-bold">📷</button>
                                                            @else
                                                                <span class="text-[10px] text-slate-300">-</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            @else
                                            <div class="p-6 text-center text-slate-400 text-xs italic">
                                                Tidak ada data perbaikan untuk alat ini pada periode ini.
                                            </div>
                                            @endif
                                        </div>
                                        @endforeach
                                    </div>
                                @else
                                    {{-- MODE NORMAL: Semua aset dalam satu tabel --}}
                                    @php
                                        $allItems = collect($assetGroups)->flatten(1);
                                    @endphp
                                    <table class="w-full text-left text-sm">
                                        <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                                            <tr>
                                                <th class="px-4 py-3">Tanggal</th>
                                                <th class="px-4 py-3">Asset/Equipment</th>
                                                <th class="px-4 py-3">Tindakan Perbaikan</th>
                                                <th class="px-4 py-3">Shift</th>
                                                <th class="px-4 py-3">Status</th>
                                                <th class="px-4 py-3 text-center">Dokumen</th>
                                                <th class="px-4 py-3 text-center">Aksi</th>
                                            </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                            @foreach($allItems as $report)
                                            <tr class="hover:bg-blue-50/50">
                                                <td class="px-4 py-3 font-medium text-xs">{{ date('d M', strtotime($report->report_date)) }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="text-blue-600 font-bold text-xs">{{ $report->asset?->tech_ident_no ?? '⚠️ (tidak dikenal)' }}</span>
                                                </td>
                                                <td class="px-4 py-3 text-slate-600 text-xs">{{ $report->action_taken ?: $report->raw_text }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="bg-slate-100 px-2 py-1 rounded text-[10px] font-bold">{{ $report->shift }}</span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    @php $s = strtolower($report->status); @endphp
                                                    <span class="px-2 py-1 rounded-full text-[10px] font-black uppercase
                                                        {{ $s == 'done' ? 'bg-green-100 text-green-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                                        {{ $s }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    @php $docs = $report->documents ? json_decode($report->documents, true) : []; @endphp
                                                    @if(count($docs) > 0)
                                                        <button onclick="openDocModal({{ $report->id }}, '{{ addslashes($report->asset?->tech_ident_no ?? 'Unknown') }}')" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 font-bold flex items-center gap-1 mx-auto">
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
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </details>
                    @endforeach
                @empty
                    <div class="bg-white p-10 rounded-2xl border border-dashed border-slate-300 text-center text-slate-400">
                        Tidak ada data perbaikan untuk kriteria ini.
                    </div>
                @endforelse
            </div>

            <!-- Pagination Links -->
            @if(isset($reportsPaginated) && $reportsPaginated->hasPages())
            <div class="mt-4 px-4 py-3 bg-white rounded-xl border border-slate-200">
                {{ $reportsPaginated->links() }}
            </div>
            @endif
        </main>
    </div>

    <!-- ==================== MODAL CREATE MAINTENANCE ==================== -->
    <div id="createReportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto">
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
                                    @foreach($allAssets->groupBy('object_type') as $type => $assets)
                                        <details class="group create-asset-group">
                                            <summary class="flex items-center gap-2 p-2 hover:bg-blue-50 rounded-lg cursor-pointer text-xs font-bold text-slate-600 list-none outline-none">
                                                <span class="transform group-open:rotate-90 transition-transform text-[10px]">▶</span>
                                                <span class="group-title">{{ $type ?: 'Tipe Lainnya' }}</span>
                                                <span class="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full ml-auto group-count">{{ count($assets) }}</span>
                                            </summary>
                                            <div class="pl-4 space-y-1 mt-1">
                                                @foreach($assets as $ast)
                                                <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition-colors hover:bg-blue-50 asset-item {{ count($selectedIds) == 1 && $selectedIds[0] == $ast->id ? 'bg-blue-100' : '' }}">
                                                    <input type="radio" name="asset_id" value="{{ $ast->id }}" required
                                                        {{ count($selectedIds) == 1 && $selectedIds[0] == $ast->id ? 'checked' : '' }}
                                                        class="w-4 h-4 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                                    <span class="text-xs text-slate-600 font-medium asset-name">{{ $ast->tech_ident_no }}</span>
                                                    <span class="text-[10px] text-slate-400 ml-auto truncate max-w-[120px] asset-desc">{{ Str::limit($ast->description, 20) }}</span>
                                                </label>
                                                @endforeach
                                            </div>
                                        </details>
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
                        <textarea name="action_taken" rows="3" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500" placeholder="Deskripsi tindakan perbaikan..."></textarea>
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
    <div id="editReportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto">
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
                                    @foreach($allAssets->groupBy('object_type') as $type => $assets)
                                        <details class="group edit-asset-group">
                                            <summary class="flex items-center gap-2 p-2 hover:bg-blue-50 rounded-lg cursor-pointer text-xs font-bold text-slate-600 list-none outline-none">
                                                <span class="transform group-open:rotate-90 transition-transform text-[10px]">▶</span>
                                                <span class="group-title">{{ $type ?: 'Tipe Lainnya' }}</span>
                                                <span class="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full ml-auto group-count">{{ count($assets) }}</span>
                                            </summary>
                                            <div class="pl-4 space-y-1 mt-1">
                                                @foreach($assets as $ast)
                                                <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition-colors hover:bg-blue-50 asset-item">
                                                    <input type="radio" name="asset_id" value="{{ $ast->id }}" required
                                                        class="w-4 h-4 text-blue-600 focus:ring-blue-500 cursor-pointer asset_radio">
                                                    <span class="text-xs text-slate-600 font-medium asset-name">{{ $ast->tech_ident_no }}</span>
                                                    <span class="text-[10px] text-slate-400 ml-auto truncate max-w-[120px] asset-desc">{{ Str::limit($ast->description, 20) }}</span>
                                                </label>
                                                @endforeach
                                            </div>
                                        </details>
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
                        <textarea name="action_taken" id="edit_action_taken" rows="3" required class="w-full border-gray-300 rounded p-2 border focus:ring-blue-500 focus:border-blue-500"></textarea>
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
    <div id="docViewModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-3xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeDocModal()" class="absolute top-4 right-4 text-gray-500 hover:text-red-500 text-2xl font-bold z-10">&times;</button>
            <h3 class="text-xl font-bold mb-4">📷 Dokumentasi Laporan</h3>
            <p class="text-sm text-slate-500 mb-4" id="doc_asset_label">Asset: -</p>
            <div id="doc_gallery" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Isi digenerate oleh JavaScript -->
            </div>
        </div>
    </div>

    <script>
        // Fungsi untuk load employees via API
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

        // === MODAL EDIT ===
        async function openEditModal(id) {
            const modal = document.getElementById('editReportModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            const res = await fetch(`/maintenance-reports/${id}/edit`);
            const data = await res.json();

            // Set radio button asset
            document.querySelectorAll('#edit_asset_list .asset_radio').forEach(r => {
                if (r.value == data.asset_id) r.checked = true;
            });
            document.getElementById('edit_action_taken').value = data.action_taken || '';
            document.getElementById('edit_report_date').value = data.report_date ? data.report_date.split(' ')[0] : '';
            document.getElementById('edit_shift').value = data.shift || '1';
            document.getElementById('edit_status').value = data.status || 'done';

            document.getElementById('editReportForm').action = `/maintenance-reports/${id}`;

            // Load employees dan set selected
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

        // === BATASI MAKSIMAL 2 CHECKBOX ===
        function limitCheckbox(el) {
            const checkboxes = document.querySelectorAll('input[name="asset_ids[]"]');
            const checked = document.querySelectorAll('input[name="asset_ids[]"]:checked');
            if (checked.length > 2) {
                el.checked = false;
                alert('Maksimal 2 alat yang bisa dibandingkan!');
            }
        }

        // === MODAL DOKUMENTASI ===
        let allReportsData = @json($allReports);
        function openDocModal(reportId, assetName) {
            // Cari report dari data yang sudah ada di halaman
            let docs = [];

            // Struktur $allReports: Year > Month > AssetGroup
            // AssetGroup bisa berupa:
            //   - Array of reports (jika hanya 1 asset atau mode tanpa filter)
            //   - Object dengan key asset_id (jika mode split dengan selectedAssets)
            function findDocsInGroup(group) {
                if (Array.isArray(group)) {
                    group.forEach(r => {
                        if (r.id == reportId && r.documents) {
                            try { docs = JSON.parse(r.documents); } catch(e) { docs = []; }
                        }
                    });
                } else if (typeof group === 'object' && group !== null) {
                    Object.values(group).forEach(val => {
                        if (Array.isArray(val)) {
                            val.forEach(r => {
                                if (r.id == reportId && r.documents) {
                                    try { docs = JSON.parse(r.documents); } catch(e) { docs = []; }
                                }
                            });
                        } else if (typeof val === 'object' && val !== null) {
                            findDocsInGroup(val); // recursive untuk nested object
                        }
                    });
                }
            }

            if (allReportsData && typeof allReportsData === 'object') {
                try {
                    Object.values(allReportsData).forEach(years => {
                        if (typeof years === 'object') {
                            Object.values(years).forEach(months => {
                                if (typeof months === 'object') {
                                    Object.values(months).forEach(groups => {
                                        findDocsInGroup(groups);
                                    });
                                }
                            });
                        }
                    });
                } catch(e) {
                    console.error('Error searching docs:', e);
                }
            }

            const modal = document.getElementById('docViewModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            document.getElementById('doc_asset_label').textContent = 'Asset: ' + (assetName || '-');

            const gallery = document.getElementById('doc_gallery');
            gallery.innerHTML = '';

            if (docs.length === 0) {
                gallery.innerHTML = '<div class="col-span-2 p-10 text-center text-slate-400">Tidak ada dokumentasi untuk laporan ini.</div>';
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

        // === SEARCH / FILTER ASSETS ===
        function filterAssets(searchText, containerId, groupClass) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const groups = container.querySelectorAll('.' + groupClass);
            const q = searchText.toLowerCase().trim();

            groups.forEach(group => {
                const items = group.querySelectorAll('.asset-item');
                let anyVisible = false;

                items.forEach(item => {
                    const name = item.querySelector('.asset-name')?.textContent?.toLowerCase() || '';
                    const desc = item.querySelector('.asset-desc')?.textContent?.toLowerCase() || '';
                    const match = q === '' || name.includes(q) || desc.includes(q);
                    item.style.display = match ? '' : 'none';
                    if (match) anyVisible = true;
                });

                // Tampilkan/sembunyikan group
                group.style.display = anyVisible || q === '' ? '' : 'none';

                // Update counter
                const visibleCount = group.querySelectorAll('.asset-item[style*="display: none"]')
                    ? group.querySelectorAll('.asset-item:not([style*="display: none"])').length
                    : items.length;
                const count = q === '' ? items.length : group.querySelectorAll('.asset-item:not([style*="display: none"])').length;
                const badge = group.querySelector('.group-count');
                if (badge) badge.textContent = count;

                // Buka grup jika ada pencarian
                const details = group.tagName === 'DETAILS' ? group : null;
                if (details && q !== '' && anyVisible) {
                    details.setAttribute('open', '');
                } else if (details && q === '') {
                    // Jangan tutup otomatis saat search kosong, biarkan user control
                }
            });
        }

        function filterCreateAssets(val) { filterAssets(val, 'create_asset_container', 'create-asset-group'); }
        function filterEditAssets(val) { filterAssets(val, 'edit_asset_container', 'edit-asset-group'); }
        function filterSidebarAssets(val) { filterAssets(val, 'sidebar_asset_list', 'sidebar-asset-group'); }

        // === RENDER GRAFIK MULTI-DATASET ===
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('mainChart').getContext('2d');
            const datasetsRaw = @json($datasetsRaw);
            const labels = @json($labelsRaw);

            // Siapkan konfigurasi dataset Chart.js
            const chartDatasets = datasetsRaw.map((ds, i) => {
                const alphaBg = ds.color + '33';
                const alphaLine = ds.color;
                return {
                    label: ds.label,
                    data: ds.data,
                    borderColor: alphaLine,
                    backgroundColor: alphaBg,
                    borderWidth: i === datasetsRaw.length - 1 ? 2 : 3,
                    borderDash: i === datasetsRaw.length - 1 ? [5, 5] : [],
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: alphaLine,
                    pointBorderWidth: 2,
                    pointRadius: i === datasetsRaw.length - 1 ? 3 : 5,
                    pointHoverRadius: 7,
                };
            });

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: chartDatasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 11, weight: 'bold' },
                                generateLabels: function(chart) {
                                    const orig = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                                    orig.forEach((label, i) => {
                                        label.fillStyle = datasetsRaw[i]?.color || '#000';
                                        label.strokeStyle = datasetsRaw[i]?.color || '#000';
                                        label.pointStyle = 'circle';
                                    });
                                    return orig;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9' },
                            ticks: { stepSize: 1, font: { size: 10 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10, weight: 'bold' } }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
