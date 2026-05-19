<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $guarded = [];

    // Relasi inverse: Area milik satu Departemen
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    // Relasi: Satu Area memiliki banyak Sub Area
    public function subAreas()
    {
        return $this->hasMany(SubArea::class);
    }

    // Relasi: Satu Area memiliki banyak Aset
    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
