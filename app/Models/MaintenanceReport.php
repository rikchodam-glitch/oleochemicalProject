<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceReport extends Model
{
    // Mengizinkan pengisian data massal (Mass Assignment)
    protected $guarded = [];

    protected $casts = [
        'report_date' => 'date',
        'duration_hours' => 'decimal:1',
        'needs_admin_review' => 'boolean',
        'ai_suggested' => 'boolean',
    ];

    /**
     * Relasi ke tabel Assets
     */
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Relasi ke tabel Employees
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
