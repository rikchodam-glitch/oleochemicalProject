<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = Employee::latest()->get();
        return view('employees', compact('employees'));
    }

    // API: daftar karyawan untuk dropdown
    public function apiList()
    {
        $employees = Employee::select('id', 'name', 'department')->orderBy('name')->get();
        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nik' => 'required|unique:employees,nik',
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'shift' => 'required|in:1,2,3,reguler',
            'phone_number' => 'required|unique:employees,phone_number',
        ]);

        $phone = $request->phone_number;
        if (substr($phone, 0, 1) == '0') {
            $phone = '62' . substr($phone, 1);
        }

        Employee::create([
            'nik' => $request->nik,
            'name' => $request->name,
            'department' => $request->department,
            'shift' => $request->shift,
            'phone_number' => $phone,
        ]);

        return redirect()->back()->with('success', 'Data Mekanik berhasil ditambahkan!');
    }

    /**
     * Update data employee
     */
    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $request->validate([
            'nik' => 'required|unique:employees,nik,' . $id,
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'shift' => 'required|in:1,2,3,reguler',
            'phone_number' => 'required|unique:employees,phone_number,' . $id,
        ]);

        $phone = $request->phone_number;
        if (substr($phone, 0, 1) == '0') {
            $phone = '62' . substr($phone, 1);
        }

        $employee->update([
            'nik' => $request->nik,
            'name' => $request->name,
            'department' => $request->department,
            'shift' => $request->shift,
            'phone_number' => $phone,
        ]);

        return redirect()->back()->with('success', 'Data ' . $employee->name . ' berhasil diperbarui!');
    }

    /**
     * Koneksikan employee ke Telegram secara manual
     */
    public function connect(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $request->validate([
            'telegram_id' => 'required|string',
        ]);

        // Cek apakah chat ID sudah dipakai employee lain
        $existing = Employee::where('telegram_id', $request->telegram_id)
            ->where('id', '!=', $id)
            ->first();

        if ($existing) {
            return redirect()->back()->withErrors(
                "Chat ID {$request->telegram_id} sudah terhubung ke {$existing->name}. Putuskan koneksi sebelumnya jika ingin menghubungkan ke {$employee->name}."
            );
        }

        $employee->update(['telegram_id' => $request->telegram_id]);

        return redirect()->back()->with('success',
            "✅ {$employee->name} berhasil dikoneksikan ke Telegram (Chat ID: {$request->telegram_id})!"
        );
    }

    /**
     * Putuskan koneksi Telegram
     */
    public function disconnect($id)
    {
        $employee = Employee::findOrFail($id);

        if (!$employee->telegram_id) {
            return redirect()->back()->withErrors("{$employee->name} belum memiliki koneksi Telegram.");
        }

        $oldChatId = $employee->telegram_id;
        $employee->update(['telegram_id' => null]);

        return redirect()->back()->with('success',
            "🔌 Koneksi Telegram {$employee->name} (Chat ID: {$oldChatId}) telah diputuskan."
        );
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->back()->with('success', 'Data Mekanik berhasil dihapus!');
    }
}
