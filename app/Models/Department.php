<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $guarded = [];

    // Relasi inverse: Departemen milik satu PT
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Relasi: Satu Departemen memiliki banyak Area
    public function areas()
    {
        return $this->hasMany(Area::class);
    }

    // Relasi: Satu Departemen memiliki banyak Aset
    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
