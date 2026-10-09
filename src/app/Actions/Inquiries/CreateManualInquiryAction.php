<?php

namespace App\Actions\Inquiries;

use App\Actions\Customers\AttachCustomerIdentityAction;
use App\Actions\Customers\FindOrCreateCustomerAction;
use App\Attribution\AttributionProjectionService;
use App\Enums\InquiryStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Inquiry;
use App\Models\Touchpoint;
use App\Support\InquirySourceKind;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateManualInquiryAction
{
    public function __construct(
        private readonly FindOrCreateCustomerAction $findOrCreateCustomer,
        private readonly RecordInquiryReceivedEventAction $recordEvent,
        private readonly AttributionProjectionService $projections,
        private readonly AttachCustomerIdentityAction $attachIdentity,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function execute(array $data, InquirySourceKind $sourceKind = InquirySourceKind::Call): Inquiry
    {
        return DB::transaction(function () use ($data, $sourceKind) {
            $match = $this->findOrCreateCustomer->execute(
                name: $data['name'] ?? null,
                phoneRaw: $data['phone'] ?? null,
                emailRaw: $data['email'] ?? null,
                comment: $data['comment'] ?? null,
            );

            /** @var \App\Models\User $actor */
            $actor = Auth::user();

            $inquiry = Inquiry::query()->create([
                'customer_id' => $match->customer->id,
                'site_id' => null,
                'source_kind' => $sourceKind->value,
                'status' => InquiryStatus::New->value,
                'name_raw' => $data['name'] ?? null,
                'phone_raw' => $data['phone'] ?? null,
                'email_raw' => $data['email'] ?? null,
                'message' => $data['message'] ?? null,
                'comment' => $data['comment'] ?? null,
                'happened_at' => now(),
                'metadata' => ['registered_by' => $actor?->id],
            ]);

            $inquiry->statusHistory()->create([
                'from_status' => null,
                'to_status' => InquiryStatus::New->value,
                'changed_by' => $actor?->id,
                'note' => 'Создано вручную',
            ]);

            Touchpoint::query()->create([
                'customer_id' => $match->customer->id,
                'inquiry_id' => $inquiry->id,
                'site_id' => null,
                'source_kind' => $sourceKind->value,
                'touched_at' => now(),
            ]);

            if ($match->conflict) {
                AuditLog::query()->create([
                    'actor_id' => $actor?->id,
                    'action' => 'customer_matching_conflict',
                    'entity_type' => Inquiry::class,
                    'entity_id' => $inquiry->id,
                    'before' => null,
                    'after' => ['note' => $match->conflictNote],
                ]);

                Activity::query()->create([
                    'type' => 'matching_conflict',
                    'direction' => 'in',
                    'customer_id' => $match->customer->id,
                    'inquiry_id' => $inquiry->id,
                    'actor_id' => $actor?->id,
                    'happened_at' => now(),
                    'channel' => $sourceKind->value,
                    'note' => $match->conflictNote,
                    'payload' => null,
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor?->id,
                'action' => 'inquiry_created_manually',
                'entity_type' => Inquiry::class,
                'entity_id' => $inquiry->id,
                'before' => null,
                'after' => ['customer_id' => $match->customer->id, 'source_kind' => $sourceKind->value],
            ]);

            $this->projections->rebuildFor($match->customer);

            $this->recordEvent->execute(
                $inquiry->id,
                $match->customer->id,
                $inquiry->created_at->toIso8601String(),
            );

            return $inquiry;
        });
    }
}
