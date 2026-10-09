<?php

namespace App\Actions\Marketing;

use App\Models\MarketingEvent;
use App\Models\Site;
use Illuminate\Support\Carbon;

class IngestMarketingEventsAction
{
    /**
     * @param array<int,array<string,mixed>> $events
     */
    public function execute(array $events, Site $site): array
    {
        $accepted = [];
        $duplicates = 0;

        \DB::transaction(function () use ($events, $site, &$accepted, &$duplicates) {
            foreach ($events as $event) {
                $occurredAt = isset($event['occurred_at'])
                    ? Carbon::parse($event['occurred_at'])
                    : now();

                $attributes = [
                    'site_id' => $site->id,
                    'event_key' => $event['event_key'],
                    'event_type' => $event['event_type'],
                    'path' => $event['path'] ?? null,
                    'utm_source' => $event['utm']['source'] ?? null,
                    'utm_medium' => $event['utm']['medium'] ?? null,
                    'utm_campaign' => $event['utm']['campaign'] ?? null,
                    'utm_content' => $event['utm']['content'] ?? null,
                    'utm_term' => $event['utm']['term'] ?? null,
                    'client_id' => $event['client_id'] ?? null,
                    'yclid' => $event['yclid'] ?? null,
                    'referrer' => $event['referrer'] ?? null,
                    'occurred_at' => $occurredAt,
                    'raw_payload' => isset($event['metadata']) ? ['metadata' => $event['metadata']] : null,
                ];

                $existing = MarketingEvent::query()
                    ->where('site_id', $site->id)
                    ->where('event_key', $event['event_key'])
                    ->exists();

                if ($existing) {
                    $duplicates++;
                    continue;
                }

                MarketingEvent::query()->create($attributes);
                $accepted[] = $attributes['event_key'];
            }
        });

        return ['accepted' => $accepted, 'duplicates' => $duplicates];
    }
}
