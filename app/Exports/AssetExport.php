<?php

namespace App\Exports;

use App\Models\Asset;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AssetExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        // Export semua aset dalam 1 sheet (tanpa grouping tipe)
        return [new AssetSheetExport(null)];
    }
}
