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
     *   "1. Aksi - done (2 jam)" / "1. Aksi (done) 1.5h"
     *   "Aksi1 (done) & Aksi2 (continue)"
     *
     * Durasi bisa ditulis:
     *   - "(2 jam)" / "(1.5 jam)" / "(30 menit)"
     *   - "2h" / "1.5h" di akhir
     *   - "durasi 2 jam" dalam teks
     */
    public function parse(string $text): array
    {
        $lines = explode("\n", trim($text));
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn($l) => $l !== '');
        $lines = array_values($lines);

        // Jika hanya 1 baris — mungkin user tidak pakai newline
        // Coba deteksi format one-liner: "Report shift1DD/MM/YYYY1. Aksi - done2. Aksi - done"
        if (count($lines) === 1) {
            $parsed = $this->parseOneLiner($lines[0]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

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
                // Deteksi shift: "Report shift 3", "Report shift1", "Laporan shift 1", "Shift 1", "shift3"
                if (preg_match('/^(?:Report|Laporan)?\s*shift\s*(\d+|reguler)/i', $line, $m)) {
                    $result['shift'] = strtolower($m[1]);
                    continue;
                }

                // Deteksi shift + nama: "Report shift 3 | Budi Santoso", "Shift 1|Budi"
                if (preg_match('/^(?:Report|Laporan)?\s*shift\s*(\d+|reguler)\s*\|\s*(.+)$/i', $line, $m)) {
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

        if (!$result['date']) {
            $result['date'] = Carbon::today();
        }
        if (!$result['shift']) {
            $result['shift'] = 'reguler';
        }

        return $result;
    }

    /**
     * Parse format satu baris tanpa newline:
     * "Report shift117/05/20261. Aksi - done2. Aksi - done (2 jam)"
     * Split berdasar pola angka+ titik + spasi (1., 2., dst)
     */
    protected function parseOneLiner(string $line): ?array
    {
        $result = [
            'shift' => null,
            'date' => null,
            'employee_name' => null,
            'items' => [],
            'raw_text' => $line,
        ];

        // Ekstrak shift: cari "shift" diikuti angka (boleh tanpa spasi)
        if (preg_match('/shift\s*(\d+|reguler)/i', $line, $m)) {
            $result['shift'] = strtolower($m[1]);
        }

        // Ekstrak tanggal: cari DD/MM/YYYY atau DD/MM/YY
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $line, $m)) {
            $day = $m[1];
            $month = $m[2];
            $year = $m[3];
            if (strlen($year) == 2) $year = '20' . $year;
            if (checkdate($month, $day, $year)) {
                $result['date'] = Carbon::createFromFormat('Y-m-d', "{$year}-{$month}-{$day}");
            }
        }

        if (!$result['date']) {
            $result['date'] = Carbon::today();
        }
        if (!$result['shift']) {
            $result['shift'] = 'reguler';
        }

        // Ekstrak items: cari pola "1. ... - done" atau "1. ... (done)"
        // Split dulu berdasarkan pola "angka. " atau "angka) "
        // Hapus bagian header (shift & date) dulu
        $body = preg_replace('/^.*?\d{1,2}\/\d{1,2}\/\d{2,4}/', '', $line);
        $body = preg_replace('/^.*?shift\s*\d+/i', '', $body);
        $body = trim($body);

        // Split items: cari pola "N. " atau "N) "
        $itemPattern = '/(\d+)[\.\)]\s*/';
        $parts = preg_split($itemPattern, $body, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $parsedItems = $this->parseLine($part);
            foreach ($parsedItems as $item) {
                $result['items'][] = $item;
            }
        }

        if (empty($result['items'])) {
            return null;
        }

        return $result;
    }

    /**
     * Parse satu baris item laporan.
     * Ekstrak: action, status, duration_hours
     *
     * Format durasi yang didukung:
     *   "(2 jam)" / "(1.5 jam)" / "(30 menit)"
     *   " - 2h" / " 1.5h" di akhir action
     *   "durasi 2 jam" / "selama 1.5 jam" dalam teks
     */
    protected function parseLine(string $line): array
    {
        $items = [];

        // Hapus nomor urut di awal
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);

        // Deteksi multi-item dengan & atau ,
        if (preg_match_all('/(.+?)\s*[-\(]\s*(done|continue|pending)\s*[\)-]?\s*(?:[,&]|$)/i', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullAction = trim($match[1]);
                $status = strtolower($match[2]);

                // Bersihkan trailing
                $fullAction = preg_replace('/\s+[&,]\s*$/', '', $fullAction);
                $fullAction = trim($fullAction);

                // Ekstrak durasi dari action text
                $duration = $this->extractDuration($fullAction);

                if (!empty($fullAction)) {
                    $items[] = [
                        'action' => $fullAction,
                        'status' => $status,
                        'duration_hours' => $duration,
                    ];
                }
            }
        }

        // Fallback: single item
        if (empty($items)) {
            if (preg_match('/^\s*(.+?)\s*[-\(]\s*(done|continue|pending)\s*[\)-]?\s*$/i', $line, $m)) {
                $fullAction = trim($m[1]);
                $duration = $this->extractDuration($fullAction);
                $items[] = [
                    'action' => $fullAction,
                    'status' => strtolower($m[2]),
                    'duration_hours' => $duration,
                ];
            } elseif (preg_match('/\((\w+)\)\s*$/', $line, $m) && in_array(strtolower($m[1]), ['done', 'continue', 'pending'])) {
                $fullAction = preg_replace('/\s*\((\w+)\)\s*$/', '', $line);
                $fullAction = trim($fullAction);
                $duration = $this->extractDuration($fullAction);
                $items[] = [
                    'action' => $fullAction,
                    'status' => strtolower($m[1]),
                    'duration_hours' => $duration,
                ];
            } else {
                $fullAction = trim($line);
                $duration = $this->extractDuration($fullAction);
                $items[] = [
                    'action' => $fullAction,
                    'status' => 'done',
                    'duration_hours' => $duration,
                ];
            }
        }

        return $items;
    }

    /**
     * Ekstrak durasi dalam jam dari string action.
     * Mencari pola seperti:
     *   - "(2 jam)" / "(1.5 jam)" / "(30 menit)" -> 2.0 / 1.5 / 0.5
     *   - " 2h" / " 1.5h" di akhir -> 2.0 / 1.5
     *   - "durasi 2 jam" / "selama 1.5 jam" dalam teks
     *
     * Method ini MENGEMBALIKAN teks action TANPA pola durasi.
     */
    protected function extractDuration(string &$action): ?float
    {
        $duration = null;

        // Cari "(X jam)" atau "(X.Y jam)" — pola terakhir di string
        if (preg_match('/\((\d+(?:[.,]\d+)?)\s*(?:jam|menit|h|m)\s*\)\s*$/i', $action, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            if (stripos($m[2], 'menit') !== false || stripos($m[2], 'm') !== false) {
                $duration = round($val / 60, 1);
            } else {
                $duration = $val;
            }
            // Hapus pola dari action
            $action = preg_replace('/\s*\(\d+(?:[.,]\d+)?\s*(?:jam|menit|h|m)\s*\)\s*$/i', '', trim($action));
        }

        // Cari " Xh" atau " X.Yh" di akhir
        elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*h\s*$/i', $action, $m)) {
            $duration = (float) str_replace(',', '.', $m[1]);
            $action = preg_replace('/\s*\d+(?:[.,]\d+)?\s*h\s*$/i', '', trim($action));
        }

        // Cari "durasi X jam" atau "selama X jam" di dalam teks
        elseif (preg_match('/(?:durasi|selama|waktu)\s+(\d+(?:[.,]\d+)?)\s*(?:jam|menit|h|m)/i', $action, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            if (stripos($m[0], 'menit') !== false || stripos($m[0], 'm') !== false && stripos($m[0], 'jam') === false) {
                $duration = round($val / 60, 1);
            } else {
                $duration = $val;
            }
            // Hapus seluruh frase durasi dari action
            $action = preg_replace('/\s*(?:durasi|selama|waktu)\s+\d+(?:[.,]\d+)?\s*(?:jam|menit|h|m)/i', '', trim($action));
        }

        $action = trim($action);
        return $duration;
    }

    /**
     * Cari equipment yang match dengan teks tindakan
     */
    public function matchEquipment(string $actionText): ?Asset
    {
        $actionText = strtoupper($actionText);

        $asset = Asset::whereRaw('UPPER(tech_ident_no) LIKE ?', ['%' . $actionText . '%'])
            ->orWhereRaw('UPPER(tech_ident_no) LIKE ?', ['%' . preg_replace('/[^A-Z0-9]/', '', $actionText) . '%'])
            ->first();

        if ($asset) return $asset;

        $asset = Asset::whereRaw('UPPER(equipment_no) LIKE ?', ['%' . preg_replace('/[^A-Z0-9]/', '', $actionText) . '%'])
            ->first();

        if ($asset) return $asset;

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
