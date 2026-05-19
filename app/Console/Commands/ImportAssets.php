<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\Area;
use App\Models\SubArea;
use Rap2hpoutre\FastExcel\FastExcel;

class ImportAssets extends Command
{
    protected $signature = 'import:assets';
    protected $description = 'Import data aset langsung dari file Excel ZPM_EPE.xlsx';

    public function handle()
    {
        // Path file Excel Anda
        $filePath = storage_path('app/ZPM_EPE.xlsx');

        if (!file_exists($filePath)) {
            $this->error("File tidak ditemukan! Pastikan Anda sudah menaruh 'ZPM_EPE.xlsx' di dalam folder storage/app/");
            return;
        }

        $this->info("Memulai proses baca file Excel...");

        // Membaca SEMUA sheet di dalam file Excel
        $sheets = (new FastExcel)->importSheets($filePath);

        foreach ($sheets as $sheetName => $sheetData) {
            $this->line("Memproses Sheet: " . $sheetName);

            foreach ($sheetData as $row) {
                // Lewati jika kolom Equipment kosong (misal baris kosong di excel)
                $equipmentNo = $row['Equipment'] ?? null;
                if (!$equipmentNo) continue;

                $description   = $row['Description'] ?? null;
                $techIdentNo   = $row['TechIdentNo.'] ?? null;
                $objectType    = $row['Object Type'] ?? null;
                $functionalLoc = $row['Functional Loc.'] ?? '';

                // Logika Auto-Mapping Lokasi
                $parts = explode('-', $functionalLoc);
                $companyCode = $parts[0] ?? null;
                $deptCode    = $parts[1] ?? null;
                $areaCode    = $parts[2] ?? null;
                $subAreaCode = $parts[3] ?? null;

                $companyId = null; $deptId = null; $areaId = null; $subAreaId = null;

                if (!empty($companyCode)) {
                    $company = Company::firstOrCreate(
                        ['code' => $companyCode],
                        ['name' => 'PT ' . $companyCode]
                    );
                    $companyId = $company->id;

                    if (!empty($deptCode)) {
                        $dept = Department::firstOrCreate(
                            ['code' => $deptCode, 'company_id' => $companyId],
                            ['name' => $deptCode]
                        );
                        $deptId = $dept->id;

                        if (!empty($areaCode)) {
                            $area = Area::firstOrCreate(
                                ['code' => $areaCode, 'department_id' => $deptId],
                                ['name' => $areaCode]
                            );
                            $areaId = $area->id;

                            if (!empty($subAreaCode)) {
                                $subArea = SubArea::firstOrCreate(
                                    ['code' => $subAreaCode, 'area_id' => $areaId],
                                    ['name' => $subAreaCode]
                                );
                                $subAreaId = $subArea->id;
                            }
                        }
                    }
                }

                // Simpan ke Database
                Asset::updateOrCreate(
                    ['equipment_no' => $equipmentNo],
                    [
                        'description'   => $description,
                        'tech_ident_no' => $techIdentNo,
                        'object_type'   => $objectType,
                        'company_id'    => $companyId,
                        'department_id' => $deptId,
                        'area_id'       => $areaId,
                        'sub_area_id'   => $subAreaId,
                    ]
                );
            }
        }

        $this->info("Mantap! Semua data dari file Excel berhasil dipetakan dan masuk ke database.");
    }
}
