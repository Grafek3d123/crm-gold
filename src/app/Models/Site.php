<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $table = 'sites';

    protected $fillable = [
        'code', 'domain', 'name', 'is_active',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }
}