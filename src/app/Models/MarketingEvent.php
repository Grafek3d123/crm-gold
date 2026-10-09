<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingEvent extends Model
{
    protected $table = 'marketing_events';

    protected $fillable = [
        'site_id', 'event_key', 'event_type', 'path',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'client_id', 'yclid', 'referrer', 'occurred_at', 'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}