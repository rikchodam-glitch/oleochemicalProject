<?php

namespace App\Services\Telegram;

use App\Models\Asset;
use Carbon\Carbon;

class TelegramReportParser
{
    /**
     * Parse teks laporan dari Telegram menjadi array item laporan
     * Support format:
     *   "Report shift 1"
     *   "DD/MM/YYYY" atau "DD/MM/YY" atau "YYYY-MM-DD"
     *   "1. Aksi - done" / "Aksi (done)" / "Aksi - done (ket)"
     *   "Aksi1 (done) & Aksi2 (continue)"
     */
    public function parse(string $text): array
    {
        $lines = explode("\n", trim($text));
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn($l) => $l !== '');

        $result = [
            'shift' => null,
            'date' => null,
            'employee_name' => null,
            'items' => [],
            'raw_text' => $text,
        ];

        $state = 'header';

        foreach ($lines as $line) {
            // Skip recap/resume lines
            if (preg_match('/^(Total|Sum|Resume|Recap)/i', $line)) {
                continue;
            }

            if ($state === 'header') {
                // Deteksi shift: "Report shift 3" atau "Laporan shift 1"
                if (preg_match('/^(?:Report|Laporan)\s+shift\s+(\d+|reguler)/i', $line, $m)) {
                    $result['shift'] = strtolower($m[1]);
                    continue;
                }

                // Deteksi shift + nama: "Report shift 3 | Budi Santoso"
                if (preg_match('/^(?:Report|Laporan)\s+shift\s+(\d+|reguler)\s*\|\s*(.+)$/i', $line, $m)) {
                    $result['shift'] = strtolower($m[1]);
                    $result['employee_name'] = trim($m[2]);
                    continue;
                }

                // Deteksi tanggal: "17/05/2026" atau "17/05/26" atau "2026-05-17"
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $line, $m)) {
                    $day = $m[1];
                    $month = $m[2];
                    $year = $m[3];
                    if (strlen($year) == 2) {
                        $year = '20' . $year;
                    }
                    if (checkdate($month, $day, $year)) {
                        $result['date'] = Carbon::createFromFormat('Y-m-d', "{$year}-{$month}-{$day}");
                        $state = 'items';
                        continue;
                    }
                }
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $line, $m)) {
                    $result['date'] = Carbon::createFromFormat('Y-m-d', $line);
                    $state = 'items';
                    continue;
                }

                // Jika line diawali angka, langsung masuk items
                if (preg_match('/^\d+[\.\)]\s*[A-Za-z]/', $line)) {
                    $state = 'items';
                } else {
                    // Bukan header dikenal, skip
                    continue;
                }
            }

            // Proses items
            if ($state === 'items') {
                $items = $this->parseLine($line);
                foreach ($items as $item) {
                    $result['items'][] = $item;
                }
            }
        }

        // Jika tidak ada tanggal, gunakan hari ini
        if (!$result['date']) {
            $result['date'] = Carbon::today();
        }

        // Jika tidak ada shift, default 'reguler'
        if (!$result['shift']) {
            $result['shift'] = 'reguler';
        }

        return $result;
    }

    /**
     * Parse satu baris item laporan
     * Format: "1. Aksi - done"
     *         "1. Aksi (done)"
     *         "3. Aksi1 (done) & Aksi2 (continue)"
     *         "1. Aksi - done (keterangan tambahan)"
     */
    protected function parseLine(string $line): array
    {
        $items = [];

        // Hapus nomor urut di awal
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);

        // Deteksi multi-item dengan & atau ,
        if (preg_match_all('/(.+?)\s*[-\(]\s*(done|continue|pending)\s*[\)-]?\s*(?:[,&]|$)/i', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $action = trim($match[1]);
                $status = strtolower($match[2]);

                // Bersihkan trailing characters
                $action = preg_replace('/\s+[&,]\s*$/', '', $action);
                $action = trim($action);

                if (!empty($action)) {
                    $items[] = [
                        'action' => $action,
                        'status' => $status,
                    ];
                }
            }
        }

        // Fallback: coba format "- Aksi - status" atau "(status)"
        if (empty($items)) {
            if (preg_match('/^\s*(.+?)\s*[-\(]\s*(done|continue|pending)\s*[\)-]?\s*$/i', $line, $m)) {
                $items[] = [
                    'action' => trim($m[1]),
                    'status' => strtolower($m[2]),
                ];
            } elseif (preg_match('/\((\w+)\)\s*$/', $line, $m) && in_array(strtolower($m[1]), ['done', 'continue', 'pending'])) {
                // Format: "Aksi (done)"
                $action = preg_replace('/\s*\((\w+)\)\s*$/', '', $line);
                $items[] = [
                    'action' => trim($action),
                    'status' => strtolower($m[1]),
                ];
            } else {
                // Jika tidak terdeteksi, default done
                $items[] = [
                    'action' => trim($line),
                    'status' => 'done',
                ];
            }
        }

        return $items;
    }

    /**
     * Cari equipment yang match dengan teks tindakan
     * Prioritas: cari tech_ident_no dulu, baru equipment_no
     */
    public function matchEquipment(string $actionText): ?Asset
    {
        $actionText = strtoupper($actionText);

        // Cari berdasarkan tech_ident_no (paling umum digunakan)
        $asset = Asset::whereRaw('UPPER(tech_ident_no) LIKE ?', ['%' . $actionText . '%'])
            ->orWhereRaw('UPPER(tech_ident_no) LIKE ?', ['%' . preg_replace('/[^A-Z0-9]/', '', $actionText) . '%'])
            ->first();

        if ($asset) return $asset;

        // Cari berdasarkan equipment_no
        $asset = Asset::whereRaw('UPPER(equipment_no) LIKE ?', ['%' . preg_replace('/[^A-Z0-9]/', '', $actionText) . '%'])
            ->first();

        if ($asset) return $asset;

        // Cari kata per kata dari action text
        $words = explode(' ', $actionText);
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^A-Z0-9]/', '', $word);
            if (strlen($cleanWord) >= 3) {
                $asset = Asset::whereRaw('UPPER(tech_ident_no) LIKE ?', ['%' . $cleanWord . '%'])
                    ->orWhereRaw('UPPER(equipment_no) LIKE ?', ['%' . $cleanWord . '%'])
                    ->first();
                if ($asset) return $asset;
            }
        }

        return null;
    }
}
