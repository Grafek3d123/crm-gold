<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryStatusHistory extends Model
{
    protected $table = 'inquiry_status_history';

    protected $fillable = [
        'inquiry_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}