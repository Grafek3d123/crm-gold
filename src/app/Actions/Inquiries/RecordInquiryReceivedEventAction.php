<?php

namespace App\Actions\Inquiries;

use App\Models\BusinessEvent;
use App\Models\Inquiry;
use App\Models\Outbox;

class RecordInquiryReceivedEventAction
{
    public function execute(int $inquiryId, int $customerId, string $receivedAt, ?string $siteCode = null): void
    {
        $payload = [
            'inquiry_id' => $inquiryId,
            'customer_id' => $customerId,
            'received_at' => $receivedAt,
            'site_code' => $siteCode,
        ];

        BusinessEvent::query()->create([
            'event_type' => 'inquiry_received',
            'entity_type' => Inquiry::class,
            'entity_id' => $inquiryId,
            'payload' => $payload,
        ]);

        Outbox::query()->create([
            'event_type' => 'inquiry_received',
            'target' => 'advertising_app',
            'payload' => $payload,
            'status' => Outbox::STATUS_PENDING,
        ]);
    }
}
