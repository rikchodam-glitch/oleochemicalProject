<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Karyawan - GAWI</title>
    @vite('resources/css/app.css')
    <style>
        .modal-overlay {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
        }
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
        .table-row-hover:hover { background-color: #f8fafc; }
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 10px; border-radius: 9999px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .stat-card { transition: all 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .search-box:focus { box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

        <!-- HEADER -->
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1">
                <span>←</span> Dashboard
            </a>
            <span class="text-slate-300">|</span>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">
                <span class="text-blue-600">👥</span> Manajemen Karyawan
            </h1>
            <span class="text-xs bg-slate-200 text-slate-500 px-3 py-1 rounded-full font-bold">{{ $employees->count() }} teknisi</span>
            <a href="{{ route('telegram.control') }}" class="ml-auto text-xs bg-cyan-50 text-cyan-700 px-4 py-2 rounded-lg hover:bg-cyan-100 font-bold border border-cyan-200 flex items-center gap-1.5">
                🤖 Panel Bot
            </a>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium">
                <span class="text-lg">✅</span> {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-3 rounded-xl mb-5 flex items-center gap-3 shadow-sm text-sm font-medium">
                <span class="text-lg">❌</span> {{ $errors->first() }}
            </div>
        @endif

        <!-- STATS CARDS -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div class="stat-card bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Teknisi</p>
                <p class="text-2xl font-black text-slate-800 mt-1">{{ $employees->count() }}</p>
            </div>
            <div class="stat-card bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Terkoneksi</p>
                <p class="text-2xl font-black text-emerald-600 mt-1">{{ $employees->whereNotNull('telegram_id')->count() }}</p>
            </div>
            <div class="stat-card bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Belum Koneksi</p>
                <p class="text-2xl font-black text-amber-500 mt-1">{{ $employees->whereNull('telegram_id')->count() }}</p>
            </div>
            <div class="stat-card bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Reguler</p>
                <p class="text-2xl font-black text-blue-500 mt-1">{{ $employees->where('shift', 'reguler')->count() }}</p>
            </div>
        </div>

        <!-- MAIN CARD -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">

            <!-- TOOLBAR -->
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap gap-3 items-center justify-between">
                <div class="flex items-center gap-3">
                    <h2 class="text-base font-bold text-slate-700">📋 Daftar Teknisi</h2>
                    <div class="relative">
                        <input type="text" id="searchInput" placeholder="Cari nama / NIK / departemen..."
                            class="search-box w-48 sm:w-64 text-xs border border-slate-200 rounded-lg py-2 pl-9 pr-3 focus:outline-none focus:border-blue-400 transition">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
                    </div>
                </div>
                <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition flex items-center gap-1.5 shadow-sm">
                    <span class="text-base">+</span> Tambah Teknisi
                </button>
            </div>

            <!-- TABLE -->
            <div class="table-container overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-[11px] uppercase font-bold tracking-wider">
                            <th class="px-5 py-3.5 border-b border-slate-100">NIK</th>
                            <th class="px-5 py-3.5 border-b border-slate-100">Nama</th>
                            <th class="px-5 py-3.5 border-b border-slate-100">Departemen</th>
                            <th class="px-5 py-3.5 border-b border-slate-100">Shift</th>
                            <th class="px-5 py-3.5 border-b border-slate-100">No. HP</th>
                            <th class="px-5 py-3.5 border-b border-slate-100">Status Bot</th>
                            <th class="px-5 py-3.5 border-b border-slate-100 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="employeeTableBody">
                        @forelse($employees as $emp)
                        <tr class="table-row-hover border-b border-slate-50" data-search="{{ strtolower($emp->nik . ' ' . $emp->name . ' ' . $emp->department) }}">
                            <td class="px-5 py-3.5 text-xs font-mono text-slate-500">{{ $emp->nik }}</td>
                            <td class="px-5 py-3.5 font-semibold text-slate-800">{{ $emp->name }}</td>
                            <td class="px-5 py-3.5 text-xs text-slate-500">{{ $emp->department }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-0.5 rounded-full
                                    {{ $emp->shift == 'reguler' ? 'bg-blue-50 text-blue-600' : 'bg-purple-50 text-purple-600' }}">
                                    {{ $emp->shift == 'reguler' ? '🗓️ Reguler' : '🔄 Shift ' . $emp->shift }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-mono text-slate-500">{{ $emp->phone_number }}</td>
                            <td class="px-5 py-3.5">
                                @if($emp->telegram_id)
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                                    <span class="badge bg-emerald-50 text-emerald-600">Terkoneksi</span>
                                    <span class="text-[10px] text-slate-400 font-mono ml-1">ID: {{ substr($emp->telegram_id, 0, 5) }}...</span>
                                </div>
                                @else
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-amber-300 inline-block"></span>
                                    <span class="badge bg-amber-50 text-amber-600">Belum Koneksi</span>
                                </div>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <div class="flex gap-1.5 justify-center flex-wrap">
                                    <!-- Edit -->
                                    <button onclick="openEditModal({{ $emp->id }})" title="Edit"
                                        class="text-xs bg-blue-50 text-blue-600 px-2.5 py-1.5 rounded-lg hover:bg-blue-100 font-bold transition">✏️</button>

                                    <!-- Connect / Disconnect -->
                                    @if(!$emp->telegram_id)
                                    <button onclick="openConnectModal({{ $emp->id }}, '{{ $emp->name }}')" title="Koneksikan ke Telegram"
                                        class="text-xs bg-cyan-50 text-cyan-600 px-2.5 py-1.5 rounded-lg hover:bg-cyan-100 font-bold transition">🔗</button>
                                    @else
                                    <form action="{{ route('employees.disconnect', $emp->id) }}" method="POST"
                                        onsubmit="return confirm('Putuskan koneksi Telegram untuk {{ $emp->name }}?')" class="inline">
                                        @csrf
                                        <button type="submit" title="Putuskan Koneksi"
                                            class="text-xs bg-orange-50 text-orange-600 px-2.5 py-1.5 rounded-lg hover:bg-orange-100 font-bold transition">🔌</button>
                                    </form>
                                    @endif

                                    <!-- Hapus -->
                                    <form action="{{ route('employees.destroy', $emp->id) }}" method="POST"
                                        onsubmit="return confirm('Yakin ingin menghapus {{ $emp->name }}?')" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" title="Hapus"
                                            class="text-xs bg-red-50 text-red-500 px-2.5 py-1.5 rounded-lg hover:bg-red-100 font-bold transition">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <p class="text-4xl mb-3">👥</p>
                                <p class="font-semibold text-slate-400">Belum ada data teknisi</p>
                                <p class="text-xs text-slate-400 mt-1">Klik "Tambah Teknisi" untuk mulai mendaftarkan.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- FOOTER INFO -->
            @if($employees->count() > 0)
            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex flex-wrap gap-4 text-xs text-slate-500">
                <span>✅ <strong class="text-emerald-600">{{ $employees->whereNotNull('telegram_id')->count() }}</strong> terkoneksi</span>
                <span>⏳ <strong class="text-amber-600">{{ $employees->whereNull('telegram_id')->count() }}</strong> belum koneksi</span>
                <span class="text-slate-300">|</span>
                <span>🔄 Shift: 1 ({{ $employees->where('shift','1')->count() }}) · 2 ({{ $employees->where('shift','2')->count() }}) · 3 ({{ $employees->where('shift','3')->count() }})</span>
            </div>
            @endif
        </div>

        <div class="mt-6 text-xs text-slate-400 flex justify-between">
            <p>&copy; {{ date('Y') }} Oleochemical Pro</p>
            <p>Powered by Erik Adam - IT Support</p>
        </div>
    </div>

    <!-- ========== MODAL TAMBAH ========== -->
    <div id="addModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-blue-600 to-blue-700">
                <h3 class="text-base font-bold text-white flex items-center gap-2">➕ Tambah Teknisi Baru</h3>
                <button onclick="closeAddModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <form action="{{ route('employees.store') }}" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">NIK</label>
                        <input type="text" name="nik" required
                            class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"
                            placeholder="Cth: 123456">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Shift</label>
                        <select name="shift" required
                            class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="1">Shift 1</option>
                            <option value="2">Shift 2</option>
                            <option value="3">Shift 3</option>
                            <option value="reguler">Reguler</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Nama Lengkap</label>
                    <input type="text" name="name" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"
                        placeholder="Cth: ERIK KHOIRUL ADAM">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Departemen / Bagian</label>
                    <input type="text" name="department" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"
                        placeholder="Cth: Maintenance Utility">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">No. HP <span class="text-[10px] font-normal lowercase text-slate-400">(WA/Telegram)</span></label>
                    <input type="text" name="phone_number" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition"
                        placeholder="Cth: 08123456789">
                    <p class="text-[11px] text-slate-400 mt-1">*Digunakan untuk verifikasi Chatbot Telegram.</p>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-lg transition text-sm shadow-sm">
                        💾 Simpan Data
                    </button>
                    <button type="button" onclick="closeAddModal()" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg transition text-sm">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== MODAL EDIT ========== -->
    <div id="editModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-amber-500 to-amber-600">
                <h3 class="text-base font-bold text-white flex items-center gap-2">✏️ Edit Data Teknisi</h3>
                <button onclick="closeEditModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <form id="editForm" method="POST" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="_method" value="POST">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">NIK</label>
                        <input type="text" name="nik" id="edit_nik" required
                            class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Shift</label>
                        <select name="shift" id="edit_shift" required
                            class="w-full border border-slate-200 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                            <option value="1">Shift 1</option>
                            <option value="2">Shift 2</option>
                            <option value="3">Shift 3</option>
                            <option value="reguler">Reguler</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Nama Lengkap</label>
                    <input type="text" name="name" id="edit_name" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Departemen</label>
                    <input type="text" name="department" id="edit_department" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">No. HP</label>
                    <input type="text" name="phone_number" id="edit_phone" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 transition">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-bold py-2.5 rounded-lg transition text-sm shadow-sm">
                        💾 Simpan Perubahan
                    </button>
                    <button type="button" onclick="closeEditModal()" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg transition text-sm">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========== MODAL KONEKSI ========== -->
    <div id="connectModal" class="modal-overlay hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-auto overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-gradient-to-r from-cyan-500 to-cyan-600">
                <h3 class="text-base font-bold text-white flex items-center gap-2">🔗 Koneksikan ke Telegram</h3>
                <button onclick="closeConnectModal()" class="text-white/70 hover:text-white text-lg font-bold transition">&times;</button>
            </div>
            <form id="connectForm" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="bg-cyan-50 border border-cyan-200 rounded-xl p-4 text-xs text-cyan-700 space-y-1">
                    <p class="font-bold">💡 Cara mendapatkan Chat ID:</p>
                    <ol class="list-decimal list-inside space-y-0.5 text-cyan-600">
                        <li>Teknisi kirim <strong>/start</strong> ke <strong>@reportOLEO_bot</strong></li>
                        <li>Buka Panel Bot → tab Log Aktivitas</li>
                        <li>Salin <strong>Chat ID</strong> yang muncul</li>
                    </ol>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Chat ID Telegram</label>
                    <input type="text" name="telegram_id" id="connect_chat_id" required
                        class="w-full border border-slate-200 rounded-lg p-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-400 transition"
                        placeholder="Cth: 7088669320">
                </div>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-700 flex items-start gap-2">
                    <span>⚠️</span> <span>Pastikan Chat ID ini benar milik teknisi yang bersangkutan.</span>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-2.5 rounded-lg transition text-sm shadow-sm">
                        🔗 Koneksikan
                    </button>
                    <button type="button" onclick="closeConnectModal()" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold py-2.5 rounded-lg transition text-sm">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const employees = @json($employees->keyBy->id);

        // SEARCH
        document.getElementById('searchInput')?.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#employeeTableBody tr').forEach(row => {
                const s = (row.getAttribute('data-search') || '').includes(q);
                row.style.display = s ? '' : 'none';
            });
        });

        // MODAL TAMBAH
        function openAddModal() { document.getElementById('addModal').classList.remove('hidden'); }
        function closeAddModal() { document.getElementById('addModal').classList.add('hidden'); }

        // MODAL EDIT
        function openEditModal(id) {
            const emp = employees[id]; if (!emp) return;
            document.getElementById('edit_nik').value = emp.nik;
            document.getElementById('edit_name').value = emp.name;
            document.getElementById('edit_department').value = emp.department || '';
            document.getElementById('edit_shift').value = emp.shift;
            document.getElementById('edit_phone').value = emp.phone_number;
            document.getElementById('editForm').action = '/employees/' + id + '/update';
            document.getElementById('editModal').classList.remove('hidden');
        }
        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        // MODAL KONEKSI
        function openConnectModal(id, name) {
            document.getElementById('connectForm').action = '/employees/' + id + '/connect';
            document.getElementById('connect_chat_id').value = '';
            document.getElementById('connect_chat_id').placeholder = 'Chat ID untuk ' + name;
            document.getElementById('connectModal').classList.remove('hidden');
        }
        function closeConnectModal() { document.getElementById('connectModal').classList.add('hidden'); }

        // CLOSE ON OUTSIDE CLICK
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
        });
    </script>
</body>
</html>
