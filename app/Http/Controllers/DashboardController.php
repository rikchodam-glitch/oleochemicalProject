<?php


namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\MaintenanceReport;

class DashboardController extends Controller
{
    public function index()
    {
        $totalAssets = Asset::count();
        $totalEmployees = Employee::count();
        $totalMaintenance = MaintenanceReport::count();

        // Ambil beberapa statistik terbaru untuk ditampilkan
        $recentReports = MaintenanceReport::with('asset', 'employee')
            ->latest()
            ->take(5)
            ->get();

        $assetGroupCount = Asset::select('object_type')
            ->whereNotNull('object_type')
            ->groupBy('object_type')
            ->selectRaw('object_type, count(*) as total')
            ->orderByDesc('total')
            ->get();

        return view('dashboard', compact(
            'totalAssets',
            'totalEmployees',
            'totalMaintenance',
            'recentReports',
            'assetGroupCount'
        ));
    }
}
