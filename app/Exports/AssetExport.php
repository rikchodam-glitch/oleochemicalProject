<?php

namespace App\Exports;

use App\Models\Asset;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AssetExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $sheets = [];
        // Ambil semua tipe barang yang unik untuk dijadikan nama sheet
        $types = Asset::select('object_type')->distinct()->pluck('object_type');

        foreach ($types as $type) {
            $sheets[] = new AssetSheetExport($type);
        }

        return $sheets;
    }
}
