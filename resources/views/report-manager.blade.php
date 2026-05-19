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
                        Total: <strong id="total_count">{{ collect($reports)->flatten(3)->count() }}</strong> laporan
                        @if(count($reports) > 0)
                            · Tahun terbaru: <strong>{{ array_key_first($reports->toArray()) }}</strong>
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

            <!-- Tabel Laporan -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" id="reports_table">
                        <thead class="bg-slate-100 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Tanggal</th>
                                <th class="px-4 py-3">Alat (Equipment)</th>
                                <th class="px-4 py-3">Teknisi</th>
                                <th class="px-4 py-3">Tindakan Perbaikan</th>
                                <th class="px-4 py-3">Shift</th>
                                <th class="px-4 py-3">Status</th>
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
                                    <span class="px-2 py-1 rounded-full text-[10px] font-black uppercase whitespace-nowrap
                                        {{ $s == 'done' ? 'bg-green-100 text-green-700' : ($s == 'pending' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                        {{ $s }}
                                    </span>
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
                                <td colspan="8" class="p-10 text-center text-slate-400">Belum ada laporan perbaikan.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination Links -->
            <div class="px-4 py-3 bg-slate-50 border-t border-slate-200">
                {{ $reportsPaginated->links() }}
            </div>

            <!-- Ringkasan Footer -->
            <div class="mt-6 grid grid-cols-3 gap-4 text-center">
                @php
                    $allFlat = collect($reports)->flatten(3);
                    $doneCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'done')->count();
                    $pendingCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'pending')->count();
                    $continueCount = $allFlat->filter(fn($r) => strtolower($r->status) == 'continue')->count();
                @endphp
                <div class="bg-green-50 p-3 rounded-lg border border-green-200">
                    <div class="text-2xl font-black text-green-600">{{ $doneCount }}</div>
                    <div class="text-[10px] text-green-700 font-bold uppercase">Done</div>
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
                                                <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition-colors hover:bg-blue-50 asset-item">
                                                    <input type="radio" name="asset_id" value="{{ $ast->id }}" required
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
                group.style.display = anyVisible || q === '' ? '' : 'none';
                const count = q === '' ? items.length : group.querySelectorAll('.asset-item:not([style*="display: none"])').length;
                const badge = group.querySelector('.group-count');
                if (badge) badge.textContent = count;
                const details = group.tagName === 'DETAILS' ? group : null;
                if (details && q !== '' && anyVisible) details.setAttribute('open', '');
            });
        }
        function filterCreateAssets(val) { filterAssets(val, 'create_asset_container', 'create-asset-group'); }

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

        // === SEARCH / FILTER ASSETS (untuk modal edit) ===
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
                group.style.display = anyVisible || q === '' ? '' : 'none';
                const count = q === '' ? items.length : group.querySelectorAll('.asset-item:not([style*="display: none"])').length;
                const badge = group.querySelector('.group-count');
                if (badge) badge.textContent = count;
                const details = group.tagName === 'DETAILS' ? group : null;
                if (details && q !== '' && anyVisible) details.setAttribute('open', '');
            });
        }
        function filterEditAssets(val) { filterAssets(val, 'edit_asset_container', 'edit-asset-group'); }

        // === FILTER LAPORAN berdasarkan tanggal (sidebar click) ===
        let activeDate = null;

        function filterByDate(date) {
            activeDate = date;
            const rows = document.querySelectorAll('.report-row');
            let visibleCount = 0;
            rows.forEach(row => {
                const match = row.dataset.date === date;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            // Tampilkan badge filter
            const badge = document.getElementById('filter_badge');
            const label = document.getElementById('filter_label');
            const totalSpan = document.getElementById('total_count');

            if (date) {
                badge.classList.remove('hidden');
                badge.classList.add('flex');
                label.textContent = date;
                totalSpan.textContent = visibleCount;
            }

            // Highlight tombol tanggal yang aktif
            document.querySelectorAll('.date-btn').forEach(btn => {
                btn.classList.toggle('bg-blue-100', btn.dataset.date === date);
                btn.classList.toggle('text-blue-700', btn.dataset.date === date);
                btn.classList.toggle('font-bold', btn.dataset.date === date);
                btn.classList.toggle('hover:bg-blue-50', btn.dataset.date !== date);
            });
        }

        function clearFilter() {
            activeDate = null;
            const rows = document.querySelectorAll('.report-row');
            rows.forEach(row => row.style.display = '');
            document.getElementById('filter_badge').classList.add('hidden');
            document.getElementById('filter_badge').classList.remove('flex');
            document.getElementById('total_count').textContent = rows.length;
            document.querySelectorAll('.date-btn').forEach(btn => {
                btn.classList.remove('bg-blue-100', 'text-blue-700', 'font-bold');
                btn.classList.add('hover:bg-blue-50');
            });
        }

        // === SEARCH LAPORAN ===
        function filterReports(val) {
            const q = val.toLowerCase().trim();
            const rows = document.querySelectorAll('.report-row');
            let totalVisible = 0;

            // Cari di kolom: tanggal, alat, teknisi, tindakan, shift, status
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const text = Array.from(cells).slice(0, 6).map(c => c.textContent.toLowerCase()).join(' ');
                const match = q === '' || text.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) totalVisible++;
            });

            document.getElementById('total_count').textContent = totalVisible;
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
    </script>
</body>
</html>
