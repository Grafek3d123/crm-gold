<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'name', 'phone_normalized', 'email_normalized', 'phone_raw', 'email_raw', 'comment', 'is_merged',
    ];

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function touchpoints(): HasMany
    {
        return $this->hasMany(Touchpoint::class);
    }

    public function firstTouchpoint(): BelongsTo
    {
        return $this->belongsTo(Touchpoint::class, 'first_touchpoint_id');
    }

    public function lastTouchpoint(): BelongsTo
    {
        return $this->belongsTo(Touchpoint::class, 'last_touchpoint_id');
    }
}