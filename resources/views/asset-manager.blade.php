<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Manager - Sistem Pendataan Aset</title>
    @vite('resources/css/app.css')
    <style>
        .modal-overlay { background: rgba(0, 0, 0, 0.4); }
        .modal-overlay.hidden { display: none; }
        .modal-box { animation: modalIn 0.2s ease-out; }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .filter-section { transition: all 0.3s ease; }
        .filter-section.collapsed { max-height: 0; padding: 0 24px; overflow: hidden; opacity: 0; margin: 0; border: none; }
        .filter-section:not(.collapsed) { max-height: 400px; padding: 24px; opacity: 1; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

        <!-- HEADER -->
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1"><span>←</span> Dashboard</a>
            <span class="text-slate-300">|</span>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900"><span class="text-blue-600">📦</span> Asset Manager</h1>
            <span class="text-xs bg-slate-100 text-slate-500 px-3 py-1 rounded-md font-semibold">{{ number_format($assets->total()) }} aset</span>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium"><span class="text-lg">✅</span> {{ session('success') }}</div>
        @endif

        @if(session('warning'))
            <div class="bg-amber-50 border border-amber-200 text-amber-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium"><span class="text-lg">⚠️</span> {{ session('warning') }}</div>
        @endif

        <!-- ACTION BAR -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-5 py-4 mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-2 flex-wrap">
                <button onclick="openCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg flex items-center gap-1.5 shadow-sm transition"><span>➕</span> Tambah Aset</button>
                <a href="{{ route('assets.export') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition">📥 Export</a>
                <a href="{{ route('assets.template') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold px-4 py-2 rounded-lg transition">📄 Template</a>
            </div>
            <form action="{{ route('assets.import') }}" method="POST" enctype="multipart/form-data" class="flex gap-2 items-center bg-blue-50 p-1.5 rounded-lg border border-blue-100">
                @csrf
                <input type="file" name="file_excel" accept=".xlsx, .csv" required class="text-xs w-40">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition">🚀 Import</button>
            </form>
        </div>

        <!-- VIEW MODE TOGGLE + FILTER -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex gap-1 items-center">
                <span class="text-xs font-semibold text-slate-500">📋 Mode Daftar</span>
            </div>
            <div class="flex gap-2 items-center">
                <button id="filterToggleBtn" onclick="toggleFilter()" class="text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 {{ $hasFilter ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">
                    🔍 Filter @if($hasFilter) <span class="bg-white text-blue-600 text-[10px] px-1.5 py-0.5 rounded-md font-semibold">!</span> @endif
                </button>
                <a href="{{ route('assets.index') }}" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-500 px-3 py-2 rounded-lg font-bold transition">↻ Reset</a>
            </div>
        </div>

        <!-- FILTER SECTION (collapsible) -->
        <div id="filterSection" class="filter-section bg-white rounded-xl border border-slate-200 shadow-sm mb-6">
            <form action="{{ route('assets.index') }}" method="GET">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">PT</label>
                        <select name="company_id" id="company" onchange="fetchDepartments(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua PT --</option>
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}" {{ request('company_id') == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Departemen</label>
                        <select name="department_id" id="department" onchange="fetchAreas(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua Departemen --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Area</label>
                        <select name="area_id" id="area" onchange="fetchSubAreas(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua Area --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Sub Area</label>
                        <select name="sub_area_id" id="sub_area" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua Sub Area --</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4 pt-3 border-t border-slate-100">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-5 py-2 rounded-lg shadow-sm transition">🔍 Terapkan Filter</button>
                </div>
            </form>
        </div>

        <!-- ==================== MODE: LIST (TABLE BIASA) ==================== -->
        <div id="modeList" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-white border-b border-slate-100 flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-700">
                    Menampilkan <span class="font-black">{{ $assets->firstItem() ?? 0 }}</span>-<span class="font-black">{{ $assets->lastItem() ?? 0 }}</span>
                    dari <span class="font-black">{{ $assets->total() }}</span> aset
                </span>
                <span class="text-[11px] font-bold bg-slate-100 text-slate-600 px-3 py-1 rounded-md">{{ $assets->total() }} total</span>
            </div>

            @include('partials.asset-table', ['items' => $assets->getCollection(), 'padClass' => 'px-5'])

            <div class="px-5 py-4 bg-white border-t border-slate-200">
                {{ $assets->links('vendor.pagination.custom-blue') }}
            </div>
        </div>

        <div class="mt-6 text-xs text-slate-400 flex justify-between">
            <p>&copy; {{ date('Y') }} Oleochemical Pro</p>
            <p>Powered by Erik Adam - IT Support</p>
        </div>
    </div>

    <!-- MODAL CREATE -->
    <div id="createModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-xl shadow-xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">➕ Tambah Aset Baru</h3>
                <button onclick="closeCreateModal()" class="text-slate-400 hover:text-red-500 text-lg font-bold transition">&times;</button>
            </div>
            <form action="{{ route('assets.store') }}" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Equipment No <span class="text-red-500">*</span></label>
                        <input type="text" name="equipment_no" required class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Cth: 1001">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Cth: FCV-6166E4-1">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Deskripsi</label>
                    <textarea name="description" rows="2" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Deskripsi alat..."></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">PT</label>
                    <select name="company_id" onchange="fetchDeptCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                        <option value="">-- Pilih PT --</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Departemen</label>
                        <select name="department_id" id="create_department" onchange="fetchAreaCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="">-- Pilih --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Area</label>
                        <select name="area_id" id="create_area" onchange="fetchSubAreaCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="">-- Pilih --</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Sub Area</label>
                    <select name="sub_area_id" id="create_sub_area" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                        <option value="">-- Pilih --</option>
                    </select>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-lg transition text-sm shadow-sm">💾 Simpan Aset</button>
                    <button type="button" onclick="closeCreateModal()" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg transition text-sm">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDIT -->
    <div id="editModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-xl shadow-xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">✏️ Edit Aset</h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-red-500 text-lg font-bold transition">&times;</button>
            </div>
            <form id="editForm" method="POST" class="p-6 space-y-4">
                @csrf @method('POST')
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Equipment No <span class="text-red-500">*</span></label>
                        <input type="text" name="equipment_no" id="edit_equipment_no" required class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" id="edit_tech_ident_no" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Deskripsi</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-bold py-2.5 rounded-lg transition text-sm shadow-sm">💾 Simpan Perubahan</button>
                    <button type="button" onclick="closeEditModal()" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg transition text-sm">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL HISTORY -->
    <div id="historyModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-xl shadow-xl max-w-2xl w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white">
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">📋 Riwayat Perbaikan</h3>
                <button onclick="closeHistoryModal()" class="text-slate-400 hover:text-red-500 text-lg font-bold transition">&times;</button>
            </div>
            <div class="p-6">
                <p class="text-sm text-slate-600 mb-4">Aset: <span id="modalAssetCode" class="font-bold text-blue-600"></span></p>
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase font-semibold tracking-normal">
                            <tr><th class="p-3 border-b">Tanggal</th><th class="p-3 border-b">Tindakan</th><th class="p-3 border-b">Status</th></tr>
                        </thead>
                        <tbody id="modalTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <a href="#" id="btnDetailLengkap" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition">Detail Maintenance →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL IMPORT CONFIRMATION -->
    <div id="importConfirmModal" class="modal-overlay hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-xl shadow-xl max-w-3xl w-full mx-auto overflow-hidden border border-amber-200">
            <div class="px-6 py-4 border-b border-amber-100 flex justify-between items-center bg-amber-50">
                <h3 class="text-base font-bold text-amber-800 flex items-center gap-2">⚠️ Konfirmasi Import Data</h3>
                <button onclick="closeImportConfirmModal()" class="text-amber-400 hover:text-red-500 text-lg font-bold transition">&times;</button>
            </div>
            <div class="p-6 max-h-[60vh] overflow-y-auto custom-scrollbar">
                @if(session('import_conflicts'))
                <div class="mb-4 flex items-center gap-3 text-sm flex-wrap">
                    <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-lg font-bold">{{ session('import_total') }} baris</span>
                    <span class="text-slate-500">diproses —</span>
                    <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-lg font-bold">{{ session('import_inserted') }} baru</span>
                    <span class="text-slate-500">·</span>
                    <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg font-bold">{{ session('import_ignored') }} diabaikan</span>
                    <span class="text-slate-500">·</span>
                    <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-lg font-bold">{{ count(session('import_conflicts')) }} bentrok</span>
                </div>
                <table class="w-full text-left text-xs border border-slate-200 rounded-lg overflow-hidden">
                    <thead class="bg-slate-50 text-slate-500 uppercase font-semibold">
                        <tr>
                            <th class="p-2 border-b w-10">#</th>
                            <th class="p-2 border-b">Equipment</th>
                            <th class="p-2 border-b">TechIdent</th>
                            <th class="p-2 border-b">Deskripsi</th>
                            <th class="p-2 border-b">Issue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="conflictTableBody">
                        @foreach(session('import_conflicts') as $conflict)
                        <tr data-asset-id="{{ $conflict['asset_id'] ?? '' }}"
                            data-equipment="{{ $conflict['equipment_no'] }}"
                            data-tech-ident="{{ $conflict['tech_ident_no'] }}"
                            data-description="{{ $conflict['description'] ?? '' }}"
                            data-company-id="{{ $conflict['company_id'] ?? '' }}"
                            data-dept-id="{{ $conflict['department_id'] ?? '' }}"
                            data-area-id="{{ $conflict['area_id'] ?? '' }}"
                            data-sub-area-id="{{ $conflict['sub_area_id'] ?? '' }}"
                            class="{{ isset($conflict['type']) && $conflict['type'] == 'update' ? 'bg-amber-50/50' : 'bg-red-50/50' }}">
                            <td class="p-2 text-slate-400">{{ $conflict['row'] }}</td>
                            <td class="p-2 font-semibold text-slate-700">{{ $conflict['equipment_no'] }}</td>
                            <td class="p-2 text-slate-600 font-mono">{{ $conflict['tech_ident_no'] }}</td>
                            <td class="p-2 text-slate-500 max-w-[200px] truncate">{{ Str::limit($conflict['description'], 30) }}</td>
                            <td class="p-2">
                                @if(isset($conflict['type']) && $conflict['type'] == 'update')
                                    <span class="text-amber-700 font-medium">⚠️ Perubahan data</span>
                                @elseif(isset($conflict['type']) && $conflict['type'] == 'duplicate_tech_ident')
                                    <span class="text-red-700 font-medium">🚫 Tech Ident duplikat</span>
                                @else
                                    <span class="text-red-700 font-medium">{{ $conflict['issue'] }}</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                <p class="text-sm text-slate-500 italic">Tidak ada data bentrok.</p>
                @endif
            </div>
            <div class="px-6 py-4 border-t border-slate-100 flex justify-between items-center bg-slate-50">
                <p class="text-[11px] text-slate-400">* Data bentrok dengan perubahan akan ditimpa.</p>
                <div class="flex gap-2">
                    <button onclick="closeImportConfirmModal()" class="px-5 py-2 bg-slate-200 hover:bg-slate-300 text-slate-600 font-semibold rounded-lg text-sm transition">Batal</button>
                    <button onclick="confirmImportUpdate()" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-lg text-sm transition shadow-sm">✅ Ya, Timpa Data Bentrok</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // GLOBAL STATE
        const HAS_FILTER = {{ $hasFilter ? 'true' : 'false' }};

        // FILTER COLLAPSE
        function toggleFilter() {
            const section = document.getElementById('filterSection');
            const btn = document.getElementById('filterToggleBtn');
            const isCollapsed = section.classList.contains('collapsed');
            section.classList.toggle('collapsed', !isCollapsed);
            if (isCollapsed) {
                btn.innerHTML = '🔍 Filter';
                btn.className = 'text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 bg-blue-600 text-white border-blue-600';
                localStorage.setItem('filterCollapsed', 'false');
            } else {
                btn.innerHTML = '🔍 Filter';
                btn.className = 'text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 bg-white text-slate-600 border-slate-300 hover:bg-slate-50';
                localStorage.setItem('filterCollapsed', 'true');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Inisialisasi state filter: priority: filter aktif > localStorage
            const filterSection = document.getElementById('filterSection');
            if (HAS_FILTER) {
                filterSection.classList.remove('collapsed');
                const btn = document.getElementById('filterToggleBtn');
                btn.innerHTML = '🔍 Filter';
                btn.className = 'text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 bg-blue-600 text-white border-blue-600';
                localStorage.setItem('filterCollapsed', 'false');
            } else {
                const savedFilterState = localStorage.getItem('filterCollapsed');
                if (savedFilterState === 'true') {
                    filterSection.classList.add('collapsed');
                    const btn = document.getElementById('filterToggleBtn');
                    btn.innerHTML = '🔍 Filter';
                    btn.className = 'text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 bg-white text-slate-600 border-slate-300 hover:bg-slate-50';
                } else if (savedFilterState === 'false') {
                    filterSection.classList.remove('collapsed');
                }
            }

            // AUTO BUKA IMPORT CONFIRM MODAL JIKA ADA CONFLICT
            @if(session('import_conflicts'))
            document.getElementById('importConfirmModal').classList.remove('hidden');
            @endif
        });

        // CASCADE DROPDOWNS
        function populateSelect(selectId, data, label) {
            const sel = document.getElementById(selectId); if (!sel) return;
            sel.innerHTML = '<option value="">-- '+label+' --</option>';
            data.forEach(i => { sel.innerHTML += '<option value="'+i.id+'">'+(i.name||i.code)+'</option>'; });
        }
        function fetchDepartments(id) { fetch('/api/departments/'+id).then(r=>r.json()).then(d=>populateSelect('department',d,'Semua Departemen')); }
        function fetchAreas(id) { fetch('/api/areas/'+id).then(r=>r.json()).then(d=>populateSelect('area',d,'Semua Area')); }
        function fetchSubAreas(id) { fetch('/api/sub-areas/'+id).then(r=>r.json()).then(d=>populateSelect('sub_area',d,'Semua Sub Area')); }
        function fetchDeptCreate(id) { fetch('/api/departments/'+id).then(r=>r.json()).then(d=>populateSelect('create_department',d,'Pilih Departemen')); }
        function fetchAreaCreate(id) { fetch('/api/areas/'+id).then(r=>r.json()).then(d=>populateSelect('create_area',d,'Pilih Area')); }
        function fetchSubAreaCreate(id) { fetch('/api/sub-areas/'+id).then(r=>r.json()).then(d=>populateSelect('create_sub_area',d,'Pilih Sub Area')); }

        // MODALS CRUD
        function openCreateModal() { document.getElementById('createModal').classList.remove('hidden'); }
        function closeCreateModal() { document.getElementById('createModal').classList.add('hidden'); }

        async function openEditModal(id) {
            const modal = document.getElementById('editModal'); modal.classList.remove('hidden');
            try {
                const res = await fetch('/assets/'+id+'/edit'); const data = await res.json();
                document.getElementById('edit_equipment_no').value = data.equipment_no || '';
                document.getElementById('edit_tech_ident_no').value = data.tech_ident_no || '';
                document.getElementById('edit_description').value = data.description || '';
                document.getElementById('editForm').action = '/assets/'+id;
            } catch(e) { console.error('Edit fetch error:', e); }
        }
        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        function openHistoryModal(id, code) {
            const modal = document.getElementById('historyModal'); modal.classList.remove('hidden');
            document.getElementById('modalAssetCode').innerText = code || '-';
            document.getElementById('btnDetailLengkap').href = '/maintenance?asset_ids[]='+id;
            document.getElementById('modalTableBody').innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-400">Memuat...</td></tr>';
            fetch('/api/maintenance-history/'+id)
                .then(r=>r.json()).then(data => {
                    const tbody = document.getElementById('modalTableBody');
                    if (!data || data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-400">Tidak ada riwayat.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = '';
                    data.forEach(i => {
                        tbody.innerHTML += '<tr class="border-b"><td class="p-3 text-xs">'+new Date(i.created_at).toLocaleDateString('id-ID')+'</td><td class="p-3 text-xs">'+(i.action_taken||i.raw_text||'-')+'</td><td class="p-3 text-xs">'+(i.status||'-')+'</td></tr>';
                    });
                }).catch(() => {
                    document.getElementById('modalTableBody').innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-400">Gagal memuat.</td></tr>';
                });
        }
        function closeHistoryModal() { document.getElementById('historyModal').classList.add('hidden'); }

        // IMPORT CONFIRMATION
        function closeImportConfirmModal() {
            document.getElementById('importConfirmModal').classList.add('hidden');
        }

        async function confirmImportUpdate() {
            const rows = document.querySelectorAll('#conflictTableBody tr[data-asset-id]:not([data-asset-id=""])');
            const updates = [];
            rows.forEach(row => {
                const assetId = row.getAttribute('data-asset-id');
                if (!assetId) return;
                updates.push({
                    asset_id: assetId,
                    equipment_no: row.getAttribute('data-equipment'),
                    tech_ident_no: row.getAttribute('data-tech-ident'),
                    description: row.getAttribute('data-description'),
                    company_id: row.getAttribute('data-company-id') || null,
                    department_id: row.getAttribute('data-dept-id') || null,
                    area_id: row.getAttribute('data-area-id') || null,
                    sub_area_id: row.getAttribute('data-sub-area-id') || null,
                });
            });

            if (updates.length === 0) {
                closeImportConfirmModal();
                return;
            }

            const btn = document.querySelector('#importConfirmModal .bg-amber-500');
            btn.innerHTML = '⏳ Memproses...';
            btn.disabled = true;

            try {
                const res = await fetch('{{ route("assets.import.confirm") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ updates })
                });
                const data = await res.json();
                if (data.success) {
                    closeImportConfirmModal();
                    window.location.href = '{{ route("assets.index") }}?import_ok=1&updated=' + data.updated;
                } else {
                    alert('Gagal: ' + (data.message || 'Unknown error'));
                    btn.innerHTML = '✅ Ya, Timpa Data Bentrok';
                    btn.disabled = false;
                }
            } catch(e) {
                alert('Gagal memproses update: ' + e.message);
                btn.innerHTML = '✅ Ya, Timpa Data Bentrok';
                btn.disabled = false;
            }
        }

        // CLOSE MODAL ON BACKDROP CLICK
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
        });

        // HANDLE IMPORT SUCCESS QUERY PARAM
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('import_ok')) {
                const updated = params.get('updated') || '0';
                const container = document.querySelector('.max-w-7xl > div:first-child');
                if (container) {
                    const alert = document.createElement('div');
                    alert.className = 'bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium';
                    alert.innerHTML = '<span class="text-lg">✅</span> Import selesai! ' + updated + ' data bentrok berhasil diperbarui.';
                    container.prepend(alert);
                    // Clean URL
                    const url = new URL(window.location);
                    url.searchParams.delete('import_ok');
                    url.searchParams.delete('updated');
                    window.history.replaceState({}, '', url);
                }
            }
        });
    </script>
</body>
</html>
