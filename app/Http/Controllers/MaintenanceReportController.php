<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Asset;
use App\Models\AssetAlias;
use App\Models\Employee;
use App\Models\MaintenanceReport;
use App\Services\AI\AiGatewayService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaintenanceReportController extends Controller
{
    // Halaman Manajemen Laporan dengan collapse Tahun > Bulan > Tanggal
    public function index(Request $request)
    {
        $allAssets = Asset::select('id', 'tech_ident_no', 'object_type', 'description')->get();

        // Filter query
        $query = MaintenanceReport::with(['asset', 'employee']);

        // Filter berdasarkan tanggal
        if ($request->filled('filter_date_from')) {
            $query->where('report_date', '>=', Carbon::parse($request->filter_date_from));
        }
        if ($request->filled('filter_date_to')) {
            $query->where('report_date', '<=', Carbon::parse($request->filter_date_to));
        }

        // Filter berdasarkan status
        if ($request->filled('filter_status')) {
            $query->where('status', $request->filter_status);
        }

        // Filter berdasarkan alat (asset)
        if ($request->filled('filter_asset')) {
            $query->where('asset_id', $request->filter_asset);
        }

        // Ambil data dengan pagination untuk tabel
        $perPage = (int)($request->per_page ?? 50);
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 200) $perPage = 200;

        $reportsPaginated = $query->latest('report_date')
            ->paginate($perPage)
            ->withQueryString();

        // Ambil semua data (tanpa filter pagination) untuk sidebar collapse
        $allReportsRaw = MaintenanceReport::with(['asset', 'employee'])
            ->latest('report_date')
            ->get();

        // GroupBy untuk sidebar collapse (3 level: Tahun > Bulan > Tanggal)
        $reports = $allReportsRaw->groupBy(function($item) {
                return date('Y', strtotime($item->report_date));
            })->map(function($yearGroup) {
                return $yearGroup->groupBy(function($item) {
                    return date('F', strtotime($item->report_date));
                })->map(function($monthGroup) {
                    return $monthGroup->groupBy(function($item) {
                        return date('Y-m-d', strtotime($item->report_date));
                    });
                });
            });

        return view('report-manager', compact('reports', 'allAssets', 'reportsPaginated'));
    }

    /**
     * Simpan laporan baru dengan bantuan AI untuk mapping asset
     */
    public function store(Request $request)
    {
        $request->validate([
            'asset_id'      => 'nullable|exists:assets,id',
            'employee_id'   => 'required|exists:employees,id',
            'action_taken'  => 'required|string',
            'status'        => 'required|in:done,continue,pending',
            'report_date'   => 'required|date',
            'shift'         => 'required|in:1,2,3,reguler',
            'documents.*'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $employee = Employee::find($request->employee_id);

        // --- INTEGRASI AI: Mapping asset dari teks teknisi ---
        $aiResult = $this->processWithAI(
            $request->action_taken,
            $employee,
            $request->shift,
            Carbon::parse($request->report_date)
        );

        // Tentukan asset_id: prioritas dari input user, fallback dari AI
        $finalAssetId = $request->asset_id;
        $aiConfidence = null;
        $aiSuggested = false;
        $needsReview = false;
        $aiProvider = null;

        if ($aiResult['success']) {
            $aiProvider = $aiResult['provider_used'];
            $aiItems = $aiResult['items'] ?? [];

            // Jika user tidak memilih asset manual, pakai saran AI
            if (!$request->asset_id && !empty($aiItems) && isset($aiItems[0]['suggested_asset_id'])) {
                $item = $aiItems[0];
                if ($item['confidence'] >= 0.8) {
                    $finalAssetId = $item['suggested_asset_id'];
                    $aiConfidence = $item['confidence'];
                    $aiSuggested = true;
                } elseif ($item['confidence'] >= 0.5) {
                    // Confidence sedang — simpan sebagai saran, admin review nanti
                    $finalAssetId = $item['suggested_asset_id'];
                    $aiConfidence = $item['confidence'];
                    $aiSuggested = true;
                    $needsReview = true;
                }
            }

            // Simpan alias baru yang dipelajari AI
            foreach ($aiResult['new_aliases'] ?? [] as $alias) {
                try {
                    AssetAlias::updateOrCreate(
                        [
                            'alias' => $alias['text'],
                            'employee_id' => $employee->id,
                        ],
                        [
                            'asset_id' => $alias['asset_id'],
                            'confidence_score' => round($alias['confidence'] * 100, 2),
                            'auto_generated' => true,
                            'usage_count' => DB::raw('usage_count + 1'),
                            'last_used_at' => now(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning("Gagal simpan alias: {$e->getMessage()}");
                }
            }
        }

        // Upload dokumen
        $documents = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $path = $file->store('report-documents', 'public');
                $documents[] = $path;
            }
        }

        $report = MaintenanceReport::create([
            'asset_id'          => $finalAssetId,
            'employee_id'       => $request->employee_id,
            'raw_text'          => $request->action_taken,
            'action_taken'      => $request->action_taken,
            'status'            => $request->status,
            'report_date'       => $request->report_date,
            'shift'             => $request->shift,
            'documents'         => !empty($documents) ? json_encode($documents) : null,
            'ai_confidence'     => $aiConfidence,
            'ai_suggested'      => $aiSuggested,
            'needs_admin_review' => $needsReview,
            'ai_provider_used'  => $aiProvider,
            'ai_notes'          => $aiResult['ai_notes'] ?? null,
            'ai_fallback_reason' => $aiResult['fallback_reason'] ?? null,
        ]);

        $msg = '✅ Laporan maintenance berhasil ditambahkan!';
        if ($aiSuggested && $aiConfidence >= 0.8) {
            $msg .= " (AI: {$aiProvider}, confidence: " . round($aiConfidence * 100) . "%)";
        } elseif ($needsReview) {
            $msg .= " ⚠️ Perlu review — confidence AI: " . round($aiConfidence * 100) . "%";
        } elseif ($aiResult['success'] && !$finalAssetId) {
            $msg .= " ❓ Asset tidak dikenali AI — lihat warnings untuk detail";
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Update laporan dengan AI re-check
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'asset_id'      => 'nullable|exists:assets,id',
            'employee_id'   => 'required|exists:employees,id',
            'action_taken'  => 'required|string',
            'status'        => 'required|in:done,continue,pending',
            'report_date'   => 'required|date',
            'shift'         => 'required|in:1,2,3,reguler',
            'documents.*'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $report = MaintenanceReport::findOrFail($id);
        $employee = Employee::find($request->employee_id);

        // Proses AI hanya jika teks berubah
        $aiResult = null;
        if ($report->raw_text !== $request->action_taken) {
            $aiResult = $this->processWithAI(
                $request->action_taken,
                $employee,
                $request->shift,
                Carbon::parse($request->report_date)
            );
        } else {
            // Jika teks tidak berubah, gunakan data AI yang sudah ada
            // untuk dipertahankan saat update
        }

        // Upload dokumen baru
        $documents = $report->documents ? json_decode($report->documents, true) : [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $path = $file->store('report-documents', 'public');
                $documents[] = $path;
            }
        }

        // Hapus dokumen yang dipilih
        if ($request->has('delete_documents')) {
            $deleteDocs = $request->delete_documents;
            foreach ($deleteDocs as $doc) {
                if (($key = array_search($doc, $documents)) !== false) {
                    $fullPath = storage_path('app/public/' . $doc);
                    if (file_exists($fullPath)) unlink($fullPath);
                    unset($documents[$key]);
                }
            }
            $documents = array_values($documents);
        }

        $updateData = [
            'asset_id'     => $request->asset_id,
            'employee_id'  => $request->employee_id,
            'action_taken' => $request->action_taken,
            'status'       => $request->status,
            'report_date'  => $request->report_date,
            'shift'        => $request->shift,
            'documents'    => !empty($documents) ? json_encode($documents) : null,
        ];

        // Update kolom AI jika ada hasil baru
        if ($aiResult && $aiResult['success']) {
            $aiItems = $aiResult['items'] ?? [];
            if (!empty($aiItems) && isset($aiItems[0]['suggested_asset_id'])) {
                $item = $aiItems[0];
                $updateData['ai_confidence'] = $item['confidence'];
                $updateData['ai_suggested'] = $item['confidence'] >= 0.5;
                $updateData['needs_admin_review'] = $item['confidence'] < 0.8;
                $updateData['ai_provider_used'] = $aiResult['provider_used'];
                $updateData['ai_notes'] = $aiResult['ai_notes'] ?? null;
                $updateData['ai_fallback_reason'] = $aiResult['fallback_reason'] ?? null;
            }

            // Simpan alias baru dari AI
            foreach ($aiResult['new_aliases'] ?? [] as $alias) {
                try {
                    AssetAlias::updateOrCreate(
                        ['alias' => $alias['text'], 'employee_id' => $employee->id],
                        [
                            'asset_id' => $alias['asset_id'],
                            'confidence_score' => round($alias['confidence'] * 100, 2),
                            'auto_generated' => true,
                            'usage_count' => DB::raw('usage_count + 1'),
                            'last_used_at' => now(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning("Gagal simpan alias saat update: {$e->getMessage()}");
                }
            }
        }

        // 🔥 FITUR AI BELAJAR DARI KOREKSI USER:
        // Jika user memilih asset, simpan teks laporan sebagai alias agar AI belajar
        $userSelectedAssetId = $request->asset_id;
        $aiSuggestedAssetId = null;
        if ($aiResult && isset($aiResult['items'][0]['suggested_asset_id'])) {
            $aiSuggestedAssetId = $aiResult['items'][0]['suggested_asset_id'];
        }

        if ($userSelectedAssetId) {
            try {
                // Ambil teks pendek sebagai alias (5 kata pertama)
                $words = explode(' ', trim($request->action_taken));
                $shortAlias = implode(' ', array_slice($words, 0, min(5, count($words))));

                if (strlen($shortAlias) > 3) {
                    $confidenceScore = 70;
                    $autoGenerated = false;
                    $notesSuffix = '';

                    if ($aiSuggestedAssetId && $userSelectedAssetId != $aiSuggestedAssetId) {
                        // User mengoreksi AI
                        $confidenceScore = 85;
                        $notesSuffix = ' | User mengoreksi: "' . $shortAlias . '" → Asset #' . $userSelectedAssetId;
                    } elseif (!$aiSuggestedAssetId) {
                        // AI tidak dapat mapping, user pilih manual
                        $confidenceScore = 75;
                        $notesSuffix = ' | User mapping manual: "' . $shortAlias . '" → Asset #' . $userSelectedAssetId;
                    } else {
                        // User setuju dengan AI
                        $confidenceScore = 90;
                    }

                    AssetAlias::updateOrCreate(
                        [
                            'alias' => $shortAlias,
                            'employee_id' => $employee->id,
                        ],
                        [
                            'asset_id' => $userSelectedAssetId,
                            'confidence_score' => $confidenceScore,
                            'auto_generated' => $autoGenerated,
                            'usage_count' => DB::raw('usage_count + 1'),
                            'last_used_at' => now(),
                        ]
                    );

                    // Update ai_notes dengan catatan mapping user
                    if ($notesSuffix) {
                        $updateData['ai_notes'] = ($updateData['ai_notes'] ?? '') . $notesSuffix;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Gagal simpan alias dari mapping user: {$e->getMessage()}");
            }
        }

        $report->update($updateData);

        $msg = '✅ Laporan maintenance berhasil diperbarui!';
        if ($aiResult && $aiResult['success']) {
            $msg .= " (AI re-check: {$aiResult['provider_used']})";
        }
        if ($userSelectedAssetId) {
            $msg .= " 🧠 AI belajar dari mapping Anda!";
        }

        return redirect()->back()->with('success', $msg);
    }

    public function destroy($id)
    {
        $report = MaintenanceReport::findOrFail($id);

        if ($report->documents) {
            $docs = json_decode($report->documents, true);
            foreach ($docs as $doc) {
                $fullPath = storage_path('app/public/' . $doc);
                if (file_exists($fullPath)) unlink($fullPath);
            }
        }

        $report->delete();
        return redirect()->back()->with('success', 'Laporan maintenance berhasil dihapus!');
    }

    /**
     * Helper: proses teks dengan AI
     */
    protected function processWithAI(string $rawText, Employee $employee, string $shift, Carbon $date): array
    {
        try {
            $gateway = new AiGatewayService();
            $gateway->withContext($employee);

            // Parse items dari teks (gunakan parser existing atau buat sederhana)
            $parsedItems = $this->simpleParseText($rawText);

            return $gateway->analyzeReport(
                $rawText,
                $parsedItems,
                $employee,
                $shift,
                $date
            );
        } catch (\Throwable $e) {
            Log::error("AI analysis failed: {$e->getMessage()}");
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'items' => [],
                'unknown_assets' => [],
                'warnings' => ['AI tidak tersedia: ' . $e->getMessage()],
                'new_aliases' => [],
                'provider_used' => null,
                'fallback_chain' => [],
            ];
        }
    }

    /**
     * Helper: parse teks mentah ke array items sederhana
     */
    protected function simpleParseText(string $text): array
    {
        $lines = explode("\n", $text);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Deteksi status dari teks
            $status = 'done';
            if (preg_match('/\b(continue|lanjut|belum|pending)\b/i', $line)) {
                $status = 'continue';
            } elseif (preg_match('/\b(pending|tunda|nanti)\b/i', $line)) {
                $status = 'pending';
            }

            $items[] = [
                'action' => $line,
                'status' => $status,
            ];
        }

        return $items;
    }

    // API: ambil data report untuk edit (JSON)
    public function edit($id)
    {
        $report = MaintenanceReport::with(['asset', 'employee'])->findOrFail($id);
        return response()->json($report);
    }
}
