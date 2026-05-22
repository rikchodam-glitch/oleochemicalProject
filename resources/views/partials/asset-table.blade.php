<table class="w-full text-left text-sm">
    <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold tracking-wider">
        <tr>
            <th class="{{ $padClass ?? 'px-5' }} py-2.5 w-1/5">Kode Barang</th>
            <th class="px-4 py-2.5 w-1/6">Equipment No</th>
            <th class="px-4 py-2.5 w-1/2">Deskripsi</th>
            <th class="px-4 py-2.5 text-center w-28">Aksi</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-50 bg-white">
        @forelse($items as $asset)
        <tr class="hover:bg-blue-50/40 transition-colors">
            <td class="{{ $padClass ?? 'px-5' }} py-2.5 font-semibold text-blue-600 text-xs">{{ $asset->tech_ident_no ?? '-' }}</td>
            <td class="px-4 py-2.5 text-gray-400 text-[11px] font-medium">{{ $asset->equipment_no ?? '-' }}</td>
            <td class="px-4 py-2.5 text-slate-600 text-xs">{{ \Illuminate\Support\Str::limit($asset->description, 50) ?? '-' }}</td>
            <td class="px-4 py-2.5 text-center">
                <div class="flex gap-1.5 justify-center">
                    <button type="button" onclick="openEditModal({{ $asset->id }})" class="text-[11px] bg-amber-50 text-amber-600 border border-amber-200 px-2 py-1 rounded-lg hover:bg-amber-100 font-bold transition-colors" title="Edit">✏️</button>
                    <form action="{{ route('assets.destroy', $asset->id) }}" method="POST" onsubmit="return confirm('Yakin hapus aset ini?')" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-[11px] bg-red-50 text-red-500 border border-red-200 px-2 py-1 rounded-lg hover:bg-red-100 font-bold transition-colors" title="Hapus">🗑️</button>
                    </form>
                    <button type="button" onclick="openHistoryModal({{ $asset->id }}, '{{ $asset->tech_ident_no }}')" class="text-[11px] bg-blue-50 text-blue-600 border border-blue-200 px-2 py-1 rounded-lg hover:bg-blue-100 font-bold transition-colors" title="Riwayat">📋</button>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" class="{{ $padClass ?? 'px-5' }} py-8 text-center text-slate-400 text-xs">Tidak ada aset di kategori ini.</td></tr>
        @endforelse
    </tbody>
</table>
