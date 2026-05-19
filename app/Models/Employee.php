<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    // Mengizinkan mass assignment
    protected $guarded = [];

    // Relasi ke tabel laporan perbaikan
    public function maintenanceReports()
    {
        return $this->hasMany(MaintenanceReport::class);
    }
}
