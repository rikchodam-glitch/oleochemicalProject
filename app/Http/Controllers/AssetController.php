<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Department;
use App\Models\Area;
use App\Models\SubArea;
use App\Models\Asset;
use Rap2hpoutre\FastExcel\FastExcel;
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

        // Mode DEFAULT: Kelompokkan berdasarkan PT > Departemen > Area > Sub Area
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

        return view('asset-manager', compact('companies', 'assets', 'request', 'hasFilter', 'grouped', 'assetsCollection'));
    }

    // --- FUNGSI EXPORT TEMPLATE KOSONG (tanpa Object Type) ---
    public function exportTemplate()
    {
        $templateData = [
            [
                'Equipment' => '',
                'Description' => '',
                'TechIdentNo.' => '',
                'Functional Loc.' => ''
            ]
        ];

        return (new FastExcel($templateData))->download('Template_Import_Asset.xlsx');
    }

    public function export()
    {
        return Excel::download(new AssetExport, 'Backup_Asset.xlsx');
    }

    // --- FUNGSI IMPORT & PENGECEKAN BENTROK (dengan popup konfirmasi) ---
    public function import(Request $request)
    {
        $request->validate([
            'file_excel' => 'required|mimes:xlsx,csv|max:51200'
        ]);

        /** @var \Illuminate\Support\Collection|null $collection */
        $collection = (new FastExcel)->import($request->file('file_excel'));

        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $conflicts = [];
        $rowsProcessed = 0;

        if (!$collection) {
            return redirect()->back()->with('warning', '⚠️ File Excel tidak valid atau kosong.');
        }

        $collection->each(function ($row) use (&$inserted, &$updated, &$ignored, &$conflicts, &$rowsProcessed) {
            $rowsProcessed++;
            $equipmentNo = $row['Equipment'] ?? null;
            $techIdentNo = $row['TechIdentNo.'] ?? null;

            // Validasi: Equipment No wajib
            if (!$equipmentNo) {
                $conflicts[] = [
                    'row' => $rowsProcessed,
                    'equipment_no' => '(kosong)',
                    'tech_ident_no' => $techIdentNo ?? '(kosong)',
                    'description' => $row['Description'] ?? '(kosong)',
                    'issue' => 'Equipment No tidak boleh kosong',
                ];
                $ignored++;
                return;
            }

            // Validasi: Tech Ident No wajib
            if (!$techIdentNo) {
                $conflicts[] = [
                    'row' => $rowsProcessed,
                    'equipment_no' => $equipmentNo,
                    'tech_ident_no' => '(kosong)',
                    'description' => $row['Description'] ?? '(kosong)',
                    'issue' => 'Tech Ident No tidak boleh kosong',
                ];
                $ignored++;
                return;
            }

            // Logika Auto Mapping Lokasi
            $parts = explode('-', $row['Functional Loc.'] ?? '');
            $companyCode = $parts[0] ?? null;
            $deptCode    = $parts[1] ?? null;
            $areaCode    = $parts[2] ?? null;
            $subAreaCode = $parts[3] ?? null;

            $companyId = null; $deptId = null; $areaId = null; $subAreaId = null;

            if ($companyCode) {
                /** @var \App\Models\Company $company */
                $company = Company::firstOrCreate(['code' => $companyCode], ['name' => 'PT ' . $companyCode]);
                $companyId = $company->id;

                if ($deptCode) {
                    /** @var \App\Models\Department $dept */
                    $dept = Department::firstOrCreate(['code' => $deptCode, 'company_id' => $companyId], ['name' => $deptCode]);
                    $deptId = $dept->id;

                    if ($areaCode) {
                        /** @var \App\Models\Area $area */
                        $area = Area::firstOrCreate(['code' => $areaCode, 'department_id' => $deptId], ['name' => $areaCode]);
                        $areaId = $area->id;

                        if ($subAreaCode) {
                            /** @var \App\Models\SubArea $subArea */
                            $subArea = SubArea::firstOrCreate(['code' => $subAreaCode, 'area_id' => $areaId], ['name' => $subAreaCode]);
                            $subAreaId = $subArea->id;
                        }
                    }
                }
            }

            // CEK BENTROK 1: Equipment No sudah ada
            $existingAsset = Asset::where('equipment_no', $equipmentNo)->first();

            if ($existingAsset) {
                // Deteksi perubahan
                $changes = [];
                if ((string)$existingAsset->tech_ident_no !== (string)$techIdentNo) {
                    $changes[] = 'TechIdentNo: "' . $existingAsset->tech_ident_no . '" → "' . $techIdentNo . '"';
                }
                if ((string)$existingAsset->description !== (string)($row['Description'] ?? '')) {
                    $changes[] = 'Deskripsi: "' . ($existingAsset->description ?? '') . '" → "' . ($row['Description'] ?? '') . '"';
                }
                if ((int)$existingAsset->company_id !== (int)$companyId) $changes[] = 'PT berubah';
                if ((int)$existingAsset->department_id !== (int)$deptId) $changes[] = 'Departemen berubah';
                if ((int)$existingAsset->area_id !== (int)$areaId) $changes[] = 'Area berubah';
                if ((int)$existingAsset->sub_area_id !== (int)$subAreaId) $changes[] = 'Sub Area berubah';

                if (!empty($changes)) {
                    $conflicts[] = [
                        'type' => 'update',
                        'row' => $rowsProcessed,
                        'equipment_no' => $equipmentNo,
                        'tech_ident_no' => $techIdentNo,
                        'description' => $row['Description'] ?? '',
                        'existing_tech_ident_no' => $existingAsset->tech_ident_no,
                        'existing_description' => $existingAsset->description,
                        'issue' => 'BENTROK - Data akan diperbarui',
                        'changes' => $changes,
                        'asset_id' => $existingAsset->id,
                        'company_id' => $companyId,
                        'department_id' => $deptId,
                        'area_id' => $areaId,
                        'sub_area_id' => $subAreaId,
                    ];
                } else {
                    $ignored++;
                }
                return;
            }

            // CEK BENTROK 2: Tech Ident No sudah dipakai equipment lain
            $existingTechIdent = Asset::where('tech_ident_no', $techIdentNo)
                ->where('equipment_no', '!=', $equipmentNo)
                ->first();

            if ($existingTechIdent) {
                $conflicts[] = [
                    'type' => 'duplicate_tech_ident',
                    'row' => $rowsProcessed,
                    'equipment_no' => $equipmentNo,
                    'tech_ident_no' => $techIdentNo,
                    'description' => $row['Description'] ?? '',
                    'issue' => 'BENTROK - Tech Ident No "' . $techIdentNo . '" sudah dipakai Equipment "' . $existingTechIdent->equipment_no . '"',
                ];
                return;
            }

            // Jika tidak ada bentrok, langsung INSERT (pakai updateOrCreate untuk safety)
            $asset = Asset::updateOrCreate(
                ['equipment_no' => $equipmentNo],
                [
                    'description'   => $row['Description'] ?? null,
                    'tech_ident_no' => $techIdentNo,
                    'company_id'    => $companyId,
                    'department_id' => $deptId,
                    'area_id'       => $areaId,
                    'sub_area_id'   => $subAreaId,
                ]
            );
            if ($asset->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        });

        // Jika ada bentrok, simpan ke session untuk popup
        if (!empty($conflicts)) {
            session()->flash('import_conflicts', $conflicts);
            session()->flash('import_inserted', $inserted);
            session()->flash('import_ignored', $ignored);
            session()->flash('import_total', $rowsProcessed);
            return redirect()->back()->with('warning', '⚠️ Ditemukan ' . count($conflicts) . ' data bermasalah. Silakan review sebelum melanjutkan.');
        }

        // Jika ada updated (dari method confirmImportUpdate)
        // ...

        $msg = "✅ Import selesai!";
        $parts = [];
        if ($inserted > 0) $parts[] = "Data Baru: $inserted";
        if ($ignored > 0) $parts[] = "Tidak Berubah: $ignored";
        if (!empty($parts)) $msg .= " " . implode(' | ', $parts);

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Konfirmasi update data bentrok — dipanggil via AJAX dari popup
     */
    public function confirmImportUpdate(Request $request)
    {
        $request->validate([
            'updates' => 'required|array',
            'updates.*.asset_id' => 'required|exists:assets,id',
            'updates.*.equipment_no' => 'required|string',
            'updates.*.tech_ident_no' => 'required|string',
            'updates.*.description' => 'nullable|string',
            'updates.*.company_id' => 'nullable|exists:companies,id',
            'updates.*.department_id' => 'nullable|exists:departments,id',
            'updates.*.area_id' => 'nullable|exists:areas,id',
            'updates.*.sub_area_id' => 'nullable|exists:sub_areas,id',
        ]);

        $updated = 0;
        foreach ($request->updates as $update) {
            $asset = Asset::find($update['asset_id']);
            if (!$asset) continue;

            $asset->update([
                'tech_ident_no' => $update['tech_ident_no'],
                'description' => $update['description'] ?? null,
                'equipment_no' => $update['equipment_no'],
                'company_id' => $update['company_id'] ?? null,
                'department_id' => $update['department_id'] ?? null,
                'area_id' => $update['area_id'] ?? null,
                'sub_area_id' => $update['sub_area_id'] ?? null,
            ]);
            $updated++;
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
        ]);
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
            'tech_ident_no' => 'required|string|max:255',
            'description'   => 'nullable|string',
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
            'tech_ident_no' => 'required|string|max:255',
            'description'   => 'nullable|string',
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
        $history = MaintenanceReport::where('asset_id', $asset_id)
            ->where('report_date', '>=', Carbon::now()->subDays(7))
            ->latest('report_date')
            ->take(10)
            ->get();

        return response()->json($history);
    }

    public function maintenanceDetail(Request $request)
    {
        // Dapatkan asset_id dari query string (checkbox)
        $selectedIds = $request->input('asset_ids', []);

        // Hanya ambil kolom yang dibutuhkan sidebar
        $allAssets = Asset::select('id', 'tech_ident_no', 'description')->get();

        // Ambil data asset terpilih
        $selectedAssets = Asset::whereIn('id', $selectedIds)->get()->keyBy('id');

        // Default Filter
        $period = $request->get('period', 'monthly');
        $year = $request->get('year', date('Y'));

        // --- Buat label sumbu X ---
        $labels = [];

        if ($period == 'weekly') {
            $startOfWeek = Carbon::now()->startOfWeek();
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

        // --- Ambil data untuk tiap asset_id, plus "semua aset" sebagai baseline ---
        $queryTargets = [];

        if (empty($selectedIds)) {
            $queryTargets[] = ['id' => null, 'label' => 'Semua Aset', 'color' => '#2563eb'];
        } else {
            foreach ($selectedAssets as $ast) {
                $queryTargets[] = ['id' => $ast->id, 'label' => $ast->tech_ident_no, 'color' => null];
            }
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

        // --- LOGIKA TABEL COLLAPSE ---
        $baseQuery = MaintenanceReport::with(['asset', 'employee']);

        if (!empty($selectedIds)) {
            $baseQuery->whereIn('asset_id', $selectedIds);
        }

        // Ambil semua untuk grouping sidebar
        $allReports = $baseQuery->latest('report_date')->get()
            ->groupBy([
                fn($item) => date('Y', strtotime($item->report_date)),
                fn($item) => date('F', strtotime($item->report_date)),
                fn($item) => $item->asset_id,
            ]);

        // Pagination untuk tabel
        $paginatedQuery = MaintenanceReport::with(['asset', 'employee']);
        if (!empty($selectedIds)) {
            $paginatedQuery->whereIn('asset_id', $selectedIds);
        }
        $reportsPaginated = $paginatedQuery->latest('report_date')->paginate(50)->withQueryString();

        // Hitung total count
        $totalReportsCount = $baseQuery->count();

        $currentPeriod = $period;
        $currentYear = $year;
        $labelsRaw = $labels;
        $datasetsRaw = $datasets;

        return view('maintenance-detail', compact(
            'allAssets', 'selectedAssets', 'selectedIds',
            'labelsRaw', 'datasetsRaw',
            'allReports', 'currentPeriod', 'currentYear',
            'reportsPaginated', 'totalReportsCount'
        ));
    }
}
