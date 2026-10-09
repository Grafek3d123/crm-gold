<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Inquiry extends Model
{
    protected $table = 'inquiries';

    protected $fillable = [
        'customer_id', 'site_id', 'source_kind', 'status', 'external_id',
        'name_raw', 'phone_raw', 'email_raw', 'message', 'comment',
        'landing_path', 'referrer',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'client_id', 'yclid', 'utm_raw', 'metadata', 'happened_at',
    ];

    protected $casts = [
        'status' => InquiryStatus::class,
        'utm_raw' => 'array',
        'metadata' => 'array',
        'happened_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InquiryStatusHistory::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function touchpoints(): HasMany
    {
        return $this->hasMany(Touchpoint::class);
    }
}