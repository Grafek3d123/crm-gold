<?php

namespace App\Actions\Inquiries;

use App\Actions\Customers\FindOrCreateCustomerAction;
use App\Actions\Customers\AttachCustomerIdentityAction;
use App\Attribution\AttributionProjectionService;
use App\Enums\InquiryStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\Touchpoint;
use App\Support\InquirySourceKind;
use Illuminate\Support\Facades\DB;

class IngestInquiryAction
{
    public function __construct(
        private readonly FindOrCreateCustomerAction $findOrCreateCustomer,
        private readonly RecordInquiryReceivedEventAction $recordEvent,
        private readonly AttributionProjectionService $projections,
        private readonly AttachCustomerIdentityAction $attachIdentity,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function executeFromApi(array $payload, Site $site, string $requestHash): Inquiry
    {
        return DB::transaction(function () use ($payload, $site, $requestHash) {
            $match = $this->findOrCreateCustomer->execute(
                name: $payload['name'] ?? null,
                phoneRaw: $payload['phone'] ?? null,
                emailRaw: $payload['email'] ?? null,
                comment: $payload['comment'] ?? null,
            );

            $inquiry = Inquiry::query()->create([
                'customer_id' => $match->customer->id,
                'site_id' => $site->id,
                'source_kind' => 'web_form',
                'status' => InquiryStatus::New->value,
                'external_id' => $payload['external_id'] ?? null,
                'name_raw' => $payload['name'] ?? null,
                'phone_raw' => $payload['phone'] ?? null,
                'email_raw' => $payload['email'] ?? null,
                'message' => $payload['message'] ?? null,
                'landing_path' => $payload['landing_path'] ?? null,
                'referrer' => $payload['referrer'] ?? null,
                'utm_source' => $payload['utm']['source'] ?? null,
                'utm_medium' => $payload['utm']['medium'] ?? null,
                'utm_campaign' => $payload['utm']['campaign'] ?? null,
                'utm_content' => $payload['utm']['content'] ?? null,
                'utm_term' => $payload['utm']['term'] ?? null,
                'client_id' => $payload['client_id'] ?? null,
                'yclid' => $payload['yclid'] ?? null,
                'utm_raw' => $payload['utm'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
                'happened_at' => now(),
            ]);

            $inquiry->statusHistory()->create([
                'from_status' => null,
                'to_status' => InquiryStatus::New->value,
                'changed_by' => null,
                'note' => null,
            ]);

            Touchpoint::query()->create([
                'customer_id' => $match->customer->id,
                'inquiry_id' => $inquiry->id,
                'site_id' => $site->id,
                'source_kind' => 'web_form',
                'utm_source' => $inquiry->utm_source,
                'utm_medium' => $inquiry->utm_medium,
                'utm_campaign' => $inquiry->utm_campaign,
                'path' => $inquiry->landing_path,
                'touched_at' => $inquiry->happened_at,
                'snapshot' => [
                    'utm' => $payload['utm'] ?? null,
                    'client_id' => $payload['client_id'] ?? null,
                    'yclid' => $payload['yclid'] ?? null,
                    'referrer' => $payload['referrer'] ?? null,
                ],
            ]);

            if (! empty($payload['client_id'])) {
                $this->attachIdentity->execute(
                    $match->customer,
                    'yandex_client_id',
                    (string) $payload['client_id'],
                );
            }

            if (! empty($payload['yclid'])) {
                $this->attachIdentity->execute(
                    $match->customer,
                    'yclid',
                    (string) $payload['yclid'],
                );
            }

            if ($match->conflict) {
                AuditLog::query()->create([
                    'actor_id' => null,
                    'action' => 'customer_matching_conflict',
                    'entity_type' => Inquiry::class,
                    'entity_id' => $inquiry->id,
                    'before' => null,
                    'after' => ['note' => $match->conflictNote],
                ]);

                // Отмечаем конфликт активностью на карточке клиента
                Activity::query()->create([
                    'type' => 'matching_conflict',
                    'direction' => 'in',
                    'customer_id' => $match->customer->id,
                    'inquiry_id' => $inquiry->id,
                    'actor_id' => null,
                    'happened_at' => now(),
                    'channel' => InquirySourceKind::WebForm->value,
                    'note' => $match->conflictNote,
                    'payload' => null,
                ]);
            }

            $this->projections->rebuildFor($match->customer);

            $this->recordEvent->execute($inquiry->id, $match->customer->id, $inquiry->created_at->toIso8601String(), $site->code);

            return $inquiry;
        });
    }
}
