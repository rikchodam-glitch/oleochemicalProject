<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Department;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Asset;
use Rap2hpoutre\FastExcel\FastExcel;
use Rap2hpoutre\FastExcel\SheetCollection;
use App\Exports\AssetExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use App\Models\MaintenanceReport;
use Illuminate\Support\Facades\DB;
class AssetController extends Controller
{
    // Menampilkan halaman utama dan menangani filter tabel
    public function index(Request $request)
    {
        $companies = Company::all();

        $query = Asset::with(['company', 'department', 'area', 'subArea'])->latest();

        // Deteksi apakah filter sedang aktif
        $hasFilter = $request->filled('company_id') ||
                     $request->filled('department_id') ||
                     $request->filled('area_id') ||
                     $request->filled('sub_area_id');

        // Tangkap request filter dari form standar
        if ($request->filled('company_id')) $query->where('company_id', $request->company_id);
        if ($request->filled('department_id')) $query->where('department_id', $request->department_id);
        if ($request->filled('area_id')) $query->where('area_id', $request->area_id);
        if ($request->filled('sub_area_id')) $query->where('sub_area_id', $request->sub_area_id);

        // Pagination: 50 data per halaman untuk performa
        $assets = $query->paginate(50)->withQueryString();

        // --- LOGIKA PENGELOMPOKKAN ---
        // Kelompokkan hanya data yang tampil di halaman saat ini untuk dikirim ke view
        $assetsCollection = $assets->getCollection();
        if ($hasFilter) {
            // MODE FILTER: Kelompokkan berdasarkan Tipe > Lokasi
            $grouped = $assetsCollection->groupBy(function($item) {
                return $item->object_type ?: 'Tipe Lainnya';
            })->map(function ($typeGroup) {
                return $typeGroup->groupBy(function($item) {
                    $loc = collect([$item->company?->code, $item->department?->code, $item->area?->code, $item->subArea?->code])
                        ->filter()->implode('-');
                    return $loc ?: 'Tanpa Lokasi';
                });
            });
        } else {
            // MODE DEFAULT: Kelompokkan berdasarkan PT > Departemen > Area > Sub Area
            $grouped = $assetsCollection->groupBy(function($item) {
                return $item->company?->code ?? 'Tanpa PT';
            })->map(function ($ptGroup) {
                return $ptGroup->groupBy(function($item) {
                    return $item->department?->code ?? 'Tanpa Dept';
                })->map(function ($deptGroup) {
                    return $deptGroup->groupBy(function($item) {
                        return $item->area?->code ?? 'Tanpa Area';
                    })->map(function ($areaGroup) {
                        return $areaGroup->groupBy(function($item) {
                            return $item->subArea?->code ?? 'Tanpa Sub';
                        });
                    });
                });
            });
        }

        // Untuk Mode Grouping: kumpulkan semua object_type beserta itemnya
        $objectTypes = Asset::whereNotNull('object_type')->distinct()->pluck('object_type');

        // Group by type untuk mode grouping (gunakan collection dari halaman saat ini)
        $groupedByType = $assetsCollection->groupBy(function($item) {
            return $item->object_type ?: 'Tipe Lainnya';
        });

        return view('asset-manager', compact('companies', 'assets', 'request', 'hasFilter', 'grouped', 'assetsCollection', 'objectTypes', 'groupedByType'));
    }
    // --- FUNGSI EXPORT DATA (UNTUK DIEDIT) ---
    // --- FUNGSI EXPORT DATA (MULTI-SHEET BERDASARKAN OBJECT TYPE) ---


    // --- FUNGSI EXPORT TEMPLATE KOSONG (MULTI-SHEET) ---
    public function exportTemplate()
    {
        // Cari tau ada 'Object Type' apa saja yang ada di database saat ini
        $objectTypes = Asset::select('object_type')
                            ->whereNotNull('object_type')
                            ->distinct()
                            ->pluck('object_type');

        $sheets = new SheetCollection();

        // Buat satu sheet kosong untuk masing-masing Object Type
        foreach ($objectTypes as $type) {
            $templateData = [
                [
                    'Equipment' => '',
                    'Description' => '',
                    'TechIdentNo.' => '',
                    'Object Type' => $type, // Sengaja diisi otomatis agar user tidak salah ketik
                    'Functional Loc.' => ''
                ]
            ];
            $sheets->put($type, $templateData);
        }

        // Tambahkan 1 sheet ekstra untuk berjaga-jaga jika ingin memasukkan tipe baru
        $sheets->put('TIPE_BARU', [[
            'Equipment' => '',
            'Description' => '',
            'TechIdentNo.' => '',
            'Object Type' => '',
            'Functional Loc.' => ''
        ]]);

        return (new FastExcel($sheets))->download('Template_Import_Asset.xlsx');
    }

    public function export()
{
    return Excel::download(new AssetExport, 'Backup_Asset.xlsx');
}
    // --- FUNGSI IMPORT & PENGECEKAN BENTROK ---
    // --- FUNGSI IMPORT & PENGECEKAN BENTROK (REVISI) ---
    public function import(Request $request)
    {
        $request->validate([
            'file_excel' => 'required|mimes:xlsx,csv|max:51200' // max 50MB
        ]);

        $collection = (new FastExcel)->import($request->file('file_excel'));

        $inserted = 0; // Counter data baru
        $updated = 0;  // Counter data yang benar-benar berubah
        $ignored = 0;  // Counter data yang sama persis (diabaikan)
        $chunkSize = 200; // Proses per 200 baris agar tidak memory overload

        // Chunking: proses dalam batch untuk mencegah memory exhaustion
        $collection->chunk($chunkSize)->each(function ($chunk) use (&$inserted, &$updated, &$ignored) {
            foreach ($chunk as $row) {
                $equipmentNo = $row['Equipment'] ?? null;
                if (!$equipmentNo) continue;

                // Logika Auto Mapping (Mendeteksi PT atau Area Baru)
                $parts = explode('-', $row['Functional Loc.'] ?? '');
                $companyCode = $parts[0] ?? null;
                $deptCode    = $parts[1] ?? null;
                $areaCode    = $parts[2] ?? null;
                $subAreaCode = $parts[3] ?? null;

            $companyId = null; $deptId = null; $areaId = null; $subAreaId = null;

                if ($companyCode) {
                    $company = Company::firstOrCreate(['code' => $companyCode], ['name' => 'PT ' . $companyCode]);
                    $companyId = $company->id;

                    if ($deptCode) {
                        $dept = Department::firstOrCreate(['code' => $deptCode, 'company_id' => $companyId], ['name' => $deptCode]);
                        $deptId = $dept->id;

                        if ($areaCode) {
                            $area = Area::firstOrCreate(['code' => $areaCode, 'department_id' => $deptId], ['name' => $areaCode]);
                            $areaId = $area->id;

                            if ($subAreaCode) {
                                $subArea = SubArea::firstOrCreate(['code' => $subAreaCode, 'area_id' => $areaId], ['name' => $subAreaCode]);
                                $subAreaId = $subArea->id;
                            }
                        }
                    }
                }

                // Pengecekan Bentrok: Cek apakah No Equipment sudah ada di Database
                $existingAsset = Asset::where('equipment_no', $equipmentNo)->first();

                if ($existingAsset) {
                    // Gunakan fill() untuk memasukkan data sementara tanpa langsung menyimpan (save)
                    $existingAsset->fill([
                        'description'   => $row['Description'],
                        'tech_ident_no' => $row['TechIdentNo.'],
                        'object_type'   => $row['Object Type'],
                        'company_id'    => $companyId,
                        'department_id' => $deptId,
                        'area_id'       => $areaId,
                        'sub_area_id'   => $subAreaId,
                    ]);

                    // Cek apakah ada perubahan atribut (isDirty)
                    if ($existingAsset->isDirty()) {
                        $existingAsset->save(); // Simpan ke database HANYA jika ada yang berubah
                        $updated++;
                    } else {
                        $ignored++; // Jika sama persis, masukkan ke hitungan 'diabaikan'
                    }

                } else {
                    // Jika belum ada di database, buat data baru
                    Asset::create([
                        'equipment_no'  => $equipmentNo,
                        'description'   => $row['Description'],
                        'tech_ident_no' => $row['TechIdentNo.'],
                        'object_type'   => $row['Object Type'],
                        'company_id'    => $companyId,
                        'department_id' => $deptId,
                        'area_id'       => $areaId,
                        'sub_area_id'   => $subAreaId,
                    ]);
                    $inserted++;
                }
            }
        }); // end chunk->each

        // Pesan notifikasi yang lebih informatif
        return redirect()->back()->with('success', "Proses selesai! Data Baru: $inserted | Diperbarui: $updated | Tidak Berubah (Diabaikan): $ignored.");
    }
    // --- API Murni untuk Dropdown (AJAX) ---
    public function getDepartments($companyId)
    {
        return response()->json(Department::where('company_id', $companyId)->get());
    }

    public function getAreas($deptId)
    {
        return response()->json(Area::where('department_id', $deptId)->get());
    }

    public function getSubAreas($areaId)
    {
        return response()->json(SubArea::where('area_id', $areaId)->get());
    }

    // --- CRUD MANUAL ASSET ---
    public function store(Request $request)
    {
        $request->validate([
            'equipment_no'  => 'required|unique:assets,equipment_no',
            'tech_ident_no' => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'object_type'   => 'nullable|string|max:255',
            'company_id'    => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id'       => 'nullable|exists:areas,id',
            'sub_area_id'   => 'nullable|exists:sub_areas,id',
        ]);

        Asset::create($request->all());

        return redirect()->back()->with('success', 'Aset baru berhasil ditambahkan!');
    }

    public function update(Request $request, $id)
    {
        $asset = Asset::findOrFail($id);

        $request->validate([
            'equipment_no'  => 'required|unique:assets,equipment_no,' . $id,
            'tech_ident_no' => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'object_type'   => 'nullable|string|max:255',
            'company_id'    => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id'       => 'nullable|exists:areas,id',
            'sub_area_id'   => 'nullable|exists:sub_areas,id',
        ]);

        $asset->update($request->all());

        return redirect()->back()->with('success', 'Data aset berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        return redirect()->back()->with('success', 'Aset berhasil dihapus!');
    }

    // API: ambil data asset untuk edit (JSON)
    public function edit($id)
    {
        $asset = Asset::with(['company', 'department', 'area', 'subArea'])->findOrFail($id);
        return response()->json($asset);
    }
    public function getHistory($asset_id)
{
    // Ambil data 1 minggu ke belakang berdasarkan report_date (bukan created_at), maksimal 10 baris
    $history = MaintenanceReport::where('asset_id', $asset_id)
                ->where('report_date', '>=', Carbon::now()->subDays(7))
                ->latest('report_date')
                ->take(10)
                ->get();

    return response()->json($history);
}

public function maintenanceDetail(Request $request)
{
    // Dapatkan 2 asset_id dari query string (checkbox)
    $selectedIds = $request->input('asset_ids', []);

    // Hanya ambil kolom yang dibutuhkan sidebar
    $allAssets = Asset::select('id', 'tech_ident_no', 'object_type', 'description')->get();

    // Ambil data 2 asset terpilih
    $selectedAssets = Asset::whereIn('id', $selectedIds)->get()->keyBy('id');

    // Default Filter
    $period = $request->get('period', 'monthly');
    $year = $request->get('year', date('Y'));

    // --- Buat label sumbu X (sama untuk semua dataset) ---
    $labels = [];

    if ($period == 'weekly') {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        for ($i = 0; $i < 7; $i++) {
            $labels[] = $startOfWeek->copy()->addDays($i)->translatedFormat('l');
        }
    } elseif ($period == 'yearly') {
        $allYears = MaintenanceReport::select(DB::raw('YEAR(report_date) as yr'))
            ->groupBy('yr')->orderBy('yr')->pluck('yr');
        $labels = $allYears->toArray();
    } else { // MONTHLY
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = Carbon::create()->month($m)->translatedFormat('M');
        }
    }

    // --- Ambil data untuk tiap asset_id (maks 2), plus "semua aset" sebagai baseline ---
    $assetIdsToQuery = $selectedIds;
    // Selalu sertakan null (semua aset) sebagai dataset pertama jika tidak ada filter
    $queryTargets = [];

    if (empty($selectedIds)) {
        // Tidak ada filter: hanya tampilkan 1 dataset "Semua Aset"
        $queryTargets[] = ['id' => null, 'label' => 'Semua Aset', 'color' => '#2563eb'];
    } else {
        // Filter aktif: tampilkan masing-masing asset sebagai dataset + "Semua Aset" sebagai pembanding
        foreach ($selectedAssets as $ast) {
            $queryTargets[] = ['id' => $ast->id, 'label' => $ast->tech_ident_no, 'color' => null];
        }
        // Tambahkan "Semua Aset" sebagai dataset pembanding
        $queryTargets[] = ['id' => null, 'label' => 'Semua Aset', 'color' => '#94a3b8'];
    }

    $datasets = [];
    $colors = ['#2563eb', '#dc2626', '#16a34a', '#ca8a04', '#8b5cf6'];
    $colorIndex = 0;

    foreach ($queryTargets as $idx => $target) {
        $stats = [];

        if ($period == 'weekly') {
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();

            $chartData = MaintenanceReport::select(DB::raw('DATE(report_date) as date'), DB::raw('COUNT(*) as total'))
                ->whereBetween('report_date', [$startOfWeek, $endOfWeek])
                ->when($target['id'], fn($q) => $q->where('asset_id', $target['id']))
                ->groupBy('date')->pluck('total', 'date');

            for ($i = 0; $i < 7; $i++) {
                $date = $startOfWeek->copy()->addDays($i)->format('Y-m-d');
                $stats[] = $chartData[$date] ?? 0;
            }
        } elseif ($period == 'yearly') {
            $chartData = MaintenanceReport::select(DB::raw('YEAR(report_date) as yr'), DB::raw('COUNT(*) as total'))
                ->when($target['id'], fn($q) => $q->where('asset_id', $target['id']))
                ->groupBy('yr')->pluck('total', 'yr');

            foreach ($labels as $yr) {
                $stats[] = $chartData[$yr] ?? 0;
            }
        } else { // MONTHLY
            $chartData = MaintenanceReport::select(DB::raw('MONTH(report_date) as month'), DB::raw('COUNT(*) as total'))
                ->whereYear('report_date', $year)
                ->when($target['id'], fn($q) => $q->where('asset_id', $target['id']))
                ->groupBy('month')->orderBy('month')->pluck('total', 'month');

            for ($m = 1; $m <= 12; $m++) {
                $stats[] = $chartData[$m] ?? 0;
            }
        }

        $color = $target['color'] ?? $colors[$colorIndex % count($colors)];
        $colorIndex++;

        $datasets[] = [
            'label' => $target['label'],
            'data'  => $stats,
            'color' => $color,
        ];
    }

    // --- LOGIKA TABEL COLLAPSE (Split berdasarkan asset jika 2 dipilih) ---
    $baseQuery = MaintenanceReport::with(['asset', 'employee']);

    if (!empty($selectedIds)) {
        $baseQuery->whereIn('asset_id', $selectedIds);
    }

    // Ambil semua untuk grouping sidebar dan dokumen (tanpa pagination agar struktur collapse utuh)
    $allReports = $baseQuery->latest('report_date')->get()
        ->groupBy([
            fn($item) => date('Y', strtotime($item->report_date)),
            fn($item) => date('F', strtotime($item->report_date)),
            fn($item) => $item->asset_id,
        ]);

    // Pagination untuk tabel jika data terlalu banyak (pakai query terpisah)
    $paginatedQuery = MaintenanceReport::with(['asset', 'employee']);
    if (!empty($selectedIds)) {
        $paginatedQuery->whereIn('asset_id', $selectedIds);
    }
    $reportsPaginated = $paginatedQuery->latest('report_date')->paginate(50)->withQueryString();

    // Hitung total count tanpa pagination untuk akurasi counter
    $totalReportsCount = $baseQuery->count();

    return view('maintenance-detail', [
        'allAssets'        => $allAssets,
        'selectedAssets'   => $selectedAssets,  // keyed by id
        'selectedIds'      => $selectedIds,
        'labelsRaw'        => $labels,
        'datasetsRaw'      => $datasets,
        'allReports'       => $allReports,
        'currentPeriod'    => $period,
        'currentYear'      => $year,
        'reportsPaginated' => $reportsPaginated,
        'totalReportsCount' => $totalReportsCount,
    ]);
}
}
