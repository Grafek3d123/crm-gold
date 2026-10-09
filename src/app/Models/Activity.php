<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Activity extends Model
{
    protected $table = 'activities';

    protected $fillable = [
        'type', 'direction', 'customer_id', 'inquiry_id', 'lead_id',
        'actor_id', 'happened_at', 'channel', 'note', 'payload',
    ];

    protected $casts = [
        'happened_at' => 'datetime',
        'payload' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}