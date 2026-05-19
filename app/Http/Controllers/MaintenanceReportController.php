<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\MaintenanceReport;
use Carbon\Carbon;

class MaintenanceReportController extends Controller
{
    // Halaman Manajemen Laporan dengan collapse Tahun > Bulan > Tanggal
    public function index()
    {
        $allAssets = Asset::select('id', 'tech_ident_no', 'object_type', 'description')->get();

        // Ambil data dengan pagination untuk tabel, tapi groupBy tetap dari semua data untuk sidebar
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

        // Pagination untuk tabel (20 per halaman)
        $reportsPaginated = MaintenanceReport::with(['asset', 'employee'])
            ->latest('report_date')
            ->paginate(20)
            ->withQueryString();

        return view('report-manager', compact('reports', 'allAssets', 'reportsPaginated'));
    }
    public function store(Request $request)
    {
        $request->validate([
            'asset_id'      => 'required|exists:assets,id',
            'employee_id'   => 'required|exists:employees,id',
            'action_taken'  => 'required|string',
            'status'        => 'required|in:done,continue,pending',
            'report_date'   => 'required|date',
            'shift'         => 'required|in:1,2,3,reguler',
            'documents.*'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // max 5MB per file
        ]);

        // Upload dokumen
        $documents = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $path = $file->store('report-documents', 'public');
                $documents[] = $path;
            }
        }

        MaintenanceReport::create([
            'asset_id'     => $request->asset_id,
            'employee_id'  => $request->employee_id,
            'raw_text'     => $request->action_taken,
            'action_taken' => $request->action_taken,
            'status'       => $request->status,
            'report_date'  => $request->report_date,
            'shift'        => $request->shift,
            'documents'    => !empty($documents) ? json_encode($documents) : null,
        ]);

        return redirect()->back()->with('success', 'Laporan maintenance berhasil ditambahkan!');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'asset_id'      => 'required|exists:assets,id',
            'employee_id'   => 'required|exists:employees,id',
            'action_taken'  => 'required|string',
            'status'        => 'required|in:done,continue,pending',
            'report_date'   => 'required|date',
            'shift'         => 'required|in:1,2,3,reguler',
            'documents.*'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $report = MaintenanceReport::findOrFail($id);
        $documents = $report->documents ? json_decode($report->documents, true) : [];

        // Upload dokumen baru
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $path = $file->store('report-documents', 'public');
                $documents[] = $path;
            }
        }

        // Hapus dokumen yang dipilih untuk dihapus
        if ($request->has('delete_documents')) {
            $deleteDocs = $request->delete_documents;
            foreach ($deleteDocs as $doc) {
                if (($key = array_search($doc, $documents)) !== false) {
                    $fullPath = storage_path('app/public/' . $doc);
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                    unset($documents[$key]);
                }
            }
            $documents = array_values($documents);
        }

        $report->update([
            'asset_id'     => $request->asset_id,
            'employee_id'  => $request->employee_id,
            'action_taken' => $request->action_taken,
            'status'       => $request->status,
            'report_date'  => $request->report_date,
            'shift'        => $request->shift,
            'documents'    => !empty($documents) ? json_encode($documents) : null,
        ]);

        return redirect()->back()->with('success', 'Laporan maintenance berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $report = MaintenanceReport::findOrFail($id);

        // Hapus file dokumen dari storage
        if ($report->documents) {
            $docs = json_decode($report->documents, true);
            foreach ($docs as $doc) {
                $fullPath = storage_path('app/public/' . $doc);
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
        }

        $report->delete();
        return redirect()->back()->with('success', 'Laporan maintenance berhasil dihapus!');
    }

    // API: ambil data report untuk edit (JSON)
    public function edit($id)
    {
        $report = MaintenanceReport::with(['asset', 'employee'])->findOrFail($id);
        return response()->json($report);
    }
}
