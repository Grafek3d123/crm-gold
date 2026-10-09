<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    protected $table = 'outbox';

    protected $fillable = [
        'event_type', 'target', 'payload', 'status', 'attempts', 'last_error', 'available_at', 'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
}