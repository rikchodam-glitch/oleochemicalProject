<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Manager - Sistem Pendataan Aset</title>
    @vite('resources/css/app.css')
    <style>
        .accordion-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .accordion-body.open { max-height: none; }
        .accordion-header { user-select: none; -webkit-user-select: none; }
        .modal-overlay { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); }
        .modal-overlay.hidden { display: none; }
        .modal-box { animation: modalIn 0.2s ease-out; }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .table-container { scrollbar-width: thin; scrollbar-color: #cbd5e1 #f8fafc; }
        .table-container::-webkit-scrollbar { height: 6px; width: 6px; }
        .table-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
        .table-container::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 8px; }
        .view-mode-btn { transition: all 0.2s ease; }
        .view-mode-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
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
            <span class="text-xs bg-slate-200 text-slate-500 px-3 py-1 rounded-full font-bold">{{ number_format($assets->total()) }} aset</span>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium"><span class="text-lg">✅</span> {{ session('success') }}</div>
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
            <div class="flex gap-1 bg-slate-100 rounded-lg p-1">
                <button id="btnListMode" onclick="setViewMode('list')" class="view-mode-btn active px-4 py-2 text-xs font-bold rounded-lg transition flex items-center gap-1.5">📋 Mode Daftar</button>
                <button id="btnGroupMode" onclick="setViewMode('group')" class="view-mode-btn px-4 py-2 text-xs font-bold rounded-lg transition flex items-center gap-1.5">📊 Mode Grouping</button>
            </div>
            <div class="flex gap-2 items-center">
                <button id="filterToggleBtn" onclick="toggleFilter()" class="text-xs font-bold px-4 py-2 rounded-lg border transition flex items-center gap-1.5 {{ $hasFilter ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">
                    🔍 Filter @if($hasFilter) <span class="bg-white text-blue-600 text-[10px] px-1.5 py-0.5 rounded-full font-bold">!</span> @endif
                </button>
                <a href="{{ route('assets.index') }}" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-500 px-3 py-2 rounded-lg font-bold transition">↻ Reset</a>
            </div>
        </div>

        <!-- FILTER SECTION (collapsible) -->
        <div id="filterSection" class="filter-section bg-white rounded-xl border border-slate-200 shadow-sm mb-6">
            <form action="{{ route('assets.index') }}" method="GET">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">PT</label>
                        <select name="company_id" id="company" onchange="fetchDepartments(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua PT --</option>
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}" {{ request('company_id') == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Departemen</label>
                        <select name="department_id" id="department" onchange="fetchAreas(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua Departemen --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Area</label>
                        <select name="area_id" id="area" onchange="fetchSubAreas(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition bg-white">
                            <option value="">-- Semua Area --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Sub Area</label>
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
            <div class="px-5 py-3 bg-gradient-to-r from-blue-50 to-white border-b border-blue-100 flex items-center justify-between">
                <span class="text-sm font-semibold text-blue-800">
                    Menampilkan <span class="font-black">{{ $assets->firstItem() ?? 0 }}</span>-<span class="font-black">{{ $assets->lastItem() ?? 0 }}</span>
                    dari <span class="font-black">{{ $assets->total() }}</span> aset
                </span>
                <span class="text-[11px] font-bold bg-blue-100 text-blue-700 px-3 py-1 rounded-full">{{ $assets->total() }} total</span>
            </div>

            @include('partials.asset-table', ['items' => $assets->getCollection(), 'padClass' => 'px-5'])

            <div class="px-5 py-4 bg-gradient-to-r from-slate-50 to-white border-t border-slate-200">
                {{ $assets->links('vendor.pagination.custom-blue') }}
            </div>
        </div>

        <!-- ==================== MODE: GROUPING (PER TIPE) ==================== -->
        <div id="modeGroup" class="hidden bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-gradient-to-r from-purple-50 to-white border-b border-purple-100 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-purple-800">📊 Grouping Berdasarkan Tipe</span>
                    <span class="text-[11px] font-bold bg-purple-100 text-purple-700 px-3 py-1 rounded-full">{{ $objectTypes->count() }} tipe</span>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-slate-500 font-semibold">Filter Tipe:</label>
                    <select id="typeFilterSelect" onchange="filterByType(this.value)" class="text-xs border border-slate-200 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-400 bg-white min-w-[160px]">
                        <option value="all">-- Semua Tipe --</option>
                        @foreach($objectTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div id="groupingContainer">
                @forelse($groupedByType as $type => $items)
                <div class="type-group border-b border-slate-100 last:border-b-0" data-type="{{ $type }}">
                    <div class="accordion-header flex items-center justify-between px-5 py-3.5 cursor-pointer bg-white hover:bg-purple-50/40 transition-colors" onclick="toggleAccordion(this)">
                        <div class="flex items-center gap-3">
                            <span class="accordion-arrow w-7 h-7 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center text-[11px] font-bold transition-transform flex-shrink-0">▶</span>
                            <span class="font-bold text-slate-800 text-sm">{{ $type ?: 'Tipe Lainnya' }}</span>
                        </div>
                        <span class="text-xs font-bold bg-purple-50 text-purple-600 px-3 py-1 rounded-full">{{ $items->count() }} aset</span>
                    </div>
                    <div class="accordion-body border-t border-slate-100 open">
                        @include('partials.asset-table', ['items' => $items, 'padClass' => 'px-5'])
                    </div>
                </div>
                @empty
                <div class="p-12 text-center text-slate-400">
                    <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="font-semibold">Tidak ada data aset</p>
                    <p class="text-xs mt-1">Silakan import Excel atau tambah aset baru.</p>
                </div>
                @endforelse
            </div>

            <div class="px-5 py-4 bg-gradient-to-r from-slate-50 to-white border-t border-slate-200 text-xs text-slate-400">
                * Filter tipe hanya menyembunyikan grup yang tidak dipilih.
            </div>
        </div>

        <div class="mt-6 text-xs text-slate-400 flex justify-between">
            <p>&copy; {{ date('Y') }} Oleochemical Pro</p>
            <p>Powered by Erik Adam - IT Support</p>
        </div>
    </div>

    <!-- MODAL CREATE -->
    <div id="createModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-blue-600 to-blue-700">
                <h3 class="text-base font-bold text-white flex items-center gap-2">➕ Tambah Aset Baru</h3>
                <button onclick="closeCreateModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <form action="{{ route('assets.store') }}" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Equipment No <span class="text-red-500">*</span></label>
                        <input type="text" name="equipment_no" required class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Cth: 1001">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Cth: FCV-6166E4-1">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Deskripsi</label>
                    <textarea name="description" rows="2" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Deskripsi alat..."></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Object Type</label>
                    <input type="text" name="object_type" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition" placeholder="Cth: ZPM-PU1, ZPM-MOT">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">PT</label>
                    <select name="company_id" onchange="fetchDeptCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                        <option value="">-- Pilih PT --</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Departemen</label>
                        <select name="department_id" id="create_department" onchange="fetchAreaCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="">-- Pilih --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Area</label>
                        <select name="area_id" id="create_area" onchange="fetchSubAreaCreate(this.value)" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="">-- Pilih --</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Sub Area</label>
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
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-amber-500 to-amber-600">
                <h3 class="text-base font-bold text-white flex items-center gap-2">✏️ Edit Aset</h3>
                <button onclick="closeEditModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <form id="editForm" method="POST" class="p-6 space-y-4">
                @csrf @method('POST')
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Equipment No <span class="text-red-500">*</span></label>
                        <input type="text" name="equipment_no" id="edit_equipment_no" required class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" id="edit_tech_ident_no" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Deskripsi</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Object Type</label>
                    <input type="text" name="object_type" id="edit_object_type" class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
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
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-blue-500 to-blue-600">
                <h3 class="text-base font-bold text-white flex items-center gap-2">📋 Riwayat Perbaikan</h3>
                <button onclick="closeHistoryModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <div class="p-6">
                <p class="text-sm text-slate-600 mb-4">Aset: <span id="modalAssetCode" class="font-bold text-blue-600"></span></p>
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-[10px] uppercase font-bold tracking-wider">
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

    <script>
        // GLOBAL STATE
        const HAS_FILTER = {{ $hasFilter ? 'true' : 'false' }};

        // ACCORDION
        function toggleAccordion(headerEl) {
            const body = headerEl.nextElementSibling;
            if (!body || !body.classList.contains('accordion-body')) return;
            const arrow = headerEl.querySelector('.accordion-arrow');
            const isOpen = body.classList.contains('open');
            if (isOpen) {
                body.classList.remove('open');
                if (arrow) arrow.style.transform = 'rotate(0deg)';
            } else {
                body.classList.add('open');
                if (arrow) arrow.style.transform = 'rotate(90deg)';
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            // Restore view mode
            const saved = localStorage.getItem('assetViewMode') || 'list';
            setViewMode(saved);

            // Open initial accordions
            document.querySelectorAll('.accordion-item > .accordion-body.open, .type-group > .accordion-body.open').forEach(body => {
                const parent = body.closest('.accordion-item, .type-group');
                if (parent) {
                    const arrow = parent.querySelector(':scope > .accordion-header .accordion-arrow');
                    if (arrow) arrow.style.transform = 'rotate(90deg)';
                }
            });

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
        });

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

        // TYPE FILTER (GROUPING MODE)
        function filterByType(type) {
            document.querySelectorAll('#groupingContainer .type-group').forEach(group => {
                if (type === 'all' || group.getAttribute('data-type') === type) {
                    group.style.display = '';
                } else {
                    group.style.display = 'none';
                }
            });
        }

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

        // MODALS
        function openCreateModal() { document.getElementById('createModal').classList.remove('hidden'); }
        function closeCreateModal() { document.getElementById('createModal').classList.add('hidden'); }

        async function openEditModal(id) {
            const modal = document.getElementById('editModal'); modal.classList.remove('hidden');
            try {
                const res = await fetch('/assets/'+id+'/edit'); const data = await res.json();
                document.getElementById('edit_equipment_no').value = data.equipment_no || '';
                document.getElementById('edit_tech_ident_no').value = data.tech_ident_no || '';
                document.getElementById('edit_description').value = data.description || '';
                document.getElementById('edit_object_type').value = data.object_type || '';
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

        // CLOSE MODAL ON BACKDROP CLICK
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
        });
    </script>
</body>
</html>
