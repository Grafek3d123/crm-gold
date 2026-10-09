<?php

namespace Tests\Feature;

use App\Models\ApiCredential;
use App\Models\MarketingEvent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class IngestMarketingEventsApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-us812-placeholder-not-real-value-0000';
    private const PATH = '/api/v1/events';

    private function siteAndCredential(): array
    {
        $site = Site::query()->create([
            'code' => 'us812',
            'domain' => 'us812.ru',
            'name' => 'us812.ru',
            'is_active' => true,
        ]);

        $credential = ApiCredential::query()->create([
            'site_id' => $site->id,
            'key_id' => 'test-us812',
            'secret_encrypted' => Crypt::encryptString(self::SECRET),
            'scope' => 'inquiries',
            'is_active' => true,
        ]);

        return [$site, $credential];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function send(array $payload, ?string $idempotencyKey): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $path = self::PATH;

        $canonical = "POST\n{$path}\n{$timestamp}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, self::SECRET);

        $server = [
            'HTTP_X_API_KEY' => 'test-us812',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    public function test_stores_allowed_fields_and_deduplicates(): void
    {
        $this->siteAndCredential();

        $payload = [
            'events' => [
                [
                    'event_key' => 'evt-1',
                    'event_type' => 'page_view',
                    'path' => '/calc/ring',
                    'utm' => ['source' => 'yandex', 'medium' => 'cpc'],
                    'client_id' => '170000000000000000',
                    'metadata' => ['screen' => '1440x900'],
                ],
                [
                    'event_key' => 'evt-2',
                    'event_type' => 'form_open',
                    'path' => '/contacts',
                ],
            ],
        ];

        $this->send($payload, 'idem-evt-1')->assertStatus(202);

        $this->assertCount(2, MarketingEvent::query()->get());

        $event = MarketingEvent::query()->where('event_key', 'evt-1')->firstOrFail();
        $this->assertSame('page_view', $event->event_type);
        $this->assertSame('yandex', $event->utm_source);
        $this->assertSame('170000000000000000', $event->client_id);
        $this->assertSame(['metadata' => ['screen' => '1440x900']], $event->raw_payload);
    }

    public function test_duplicate_event_key_is_not_stored_twice(): void
    {
        $this->siteAndCredential();

        $payload = [
            'events' => [
                ['event_key' => 'evt-dup', 'event_type' => 'page_view', 'path' => '/'],
            ],
        ];

        $this->send($payload, 'idem-evt-dup-1')->assertStatus(202);
        $this->send($payload, 'idem-evt-dup-2')->assertStatus(202);

        $this->assertCount(1, MarketingEvent::query()->get());
    }

    public function test_unknown_event_key_in_batch_is_deduplicated(): void
    {
        $this->siteAndCredential();

        $payload = [
            'events' => [
                ['event_key' => 'evt-batch-1', 'event_type' => 'page_view'],
                ['event_key' => 'evt-batch-1', 'event_type' => 'page_view'],
                ['event_key' => 'evt-batch-2', 'event_type' => 'form_open'],
            ],
        ];

        $response = $this->send($payload, 'idem-evt-batch');

        $response->assertStatus(202)->assertJsonPath('data.accepted', 2);
        $this->assertCount(2, MarketingEvent::query()->get());
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->siteAndCredential();

        $body = json_encode(['events' => [['event_key' => 'evt-x', 'event_type' => 'page_view']]]);

        $response = $this->call('POST', self::PATH, [], [], [], [
            'HTTP_X_API_KEY' => 'test-us812',
            'HTTP_X_TIMESTAMP' => (string) time(),
            'HTTP_X_SIGNATURE' => str_repeat('0', 64),
            'HTTP_IDEMPOTENCY_KEY' => 'idem-evt-x',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->assertDatabaseCount('marketing_events', 0);
    }

    public function test_requires_idempotency_key(): void
    {
        $this->siteAndCredential();

        $payload = ['events' => [['event_key' => 'evt-n', 'event_type' => 'page_view']]];

        $this->send($payload, null)->assertStatus(422)->assertJsonPath('error.code', 'missing_idempotency_key');
    }

    public function test_requires_valid_event_schema(): void
    {
        $this->siteAndCredential();

        $this->send(['events' => [['event_type' => 'page_view']]], 'idem-evt-bad')
            ->assertStatus(422);
    }
}
