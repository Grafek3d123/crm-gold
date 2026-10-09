<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Touchpoint extends Model
{
    protected $table = 'touchpoints';

    protected $fillable = [
        'customer_id', 'inquiry_id', 'site_id', 'source_kind',
        'utm_source', 'utm_medium', 'utm_campaign', 'path', 'touched_at', 'snapshot',
    ];

    protected $casts = [
        'touched_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}