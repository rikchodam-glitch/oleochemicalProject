<?php

namespace App\Exports;

use App\Models\Asset;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class AssetSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $type;

    public function __construct($type)
    {
        $this->type = $type;
    }

    public function query()
    {
        return Asset::query();
    }

    public function title(): string
    {
        return 'Semua Aset';
    }

    public function headings(): array
    {
        return ["Equipment", "Description", "TechIdentNo.", "Functional Loc."];
    }

    public function map($asset): array
    {
        return [
            $asset->equipment_no,
            $asset->description,
            $asset->tech_ident_no,
            collect([$asset->company?->code, $asset->department?->code, $asset->area?->code, $asset->subArea?->code])->filter()->implode('-')
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Style untuk Header (Baris 1) - Biru Tua, Font Putih, Tebal
        $sheet->getStyle('A1:D1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78']
            ],
            'alignment' => ['horizontal' => 'center']
        ]);

        // Tambahkan Border ke seluruh tabel data
        $sheet->getStyle('A1:D' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9D9D9'],
                ],
            ],
        ]);

        // Zebra Striping (Baris Genap diberi warna abu-abu muda)
        for ($i = 2; $i <= $lastRow; $i++) {
            if ($i % 2 == 0) {
                $sheet->getStyle('A' . $i . ':D' . $i)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
        }
    }
}
