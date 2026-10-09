<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Idempotency extends Model
{
    protected $table = 'idempotency';

    protected $fillable = [
        'scope', 'key', 'request_hash', 'http_status', 'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}