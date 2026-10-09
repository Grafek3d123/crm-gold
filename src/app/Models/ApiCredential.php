<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    protected $table = 'api_credentials';

    protected $fillable = [
        'site_id', 'key_id', 'secret_encrypted', 'scope', 'is_active', 'rotated_at',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}