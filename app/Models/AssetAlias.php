<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAlias extends Model
{
    protected $guarded = [];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'auto_generated' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Scope: cari alias yang cocok (untuk teknisi tertentu atau global)
     */
    public function scopeForEmployee($query, ?int $employeeId)
    {
        return $query->where(function($q) use ($employeeId) {
            $q->whereNull('employee_id')
              ->orWhere('employee_id', $employeeId);
        });
    }
}
