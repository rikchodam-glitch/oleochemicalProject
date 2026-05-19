<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = [];

    // Relasi: Satu PT memiliki banyak Departemen
    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    // Relasi: Satu PT memiliki banyak Aset
    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
