<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceReport extends Model
{
    // Mengizinkan pengisian data massal (Mass Assignment)
    protected $guarded = [];

    /**
     * Relasi ke tabel Assets
     * Menghubungkan laporan ini dengan alat/equipment terkait
     */
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Relasi ke tabel Employees
     * Menghubungkan laporan ini dengan mekanik/user yang mengerjakan
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
