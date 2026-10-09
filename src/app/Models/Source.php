<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $table = 'sources';

    protected $fillable = [
        'code', 'name', 'is_active',
    ];
}