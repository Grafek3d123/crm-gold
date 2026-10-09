<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessEvent extends Model
{
    protected $table = 'business_events';

    protected $fillable = [
        'event_type', 'entity_type', 'entity_id', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}