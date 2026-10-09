<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentity extends Model
{
    protected $table = 'customer_identities';

    protected $fillable = [
        'customer_id', 'provider', 'external_key', 'payload',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}