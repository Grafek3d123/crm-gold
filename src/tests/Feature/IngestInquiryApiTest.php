<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class IngestInquiryApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-us812-placeholder-not-real-value-0000';
    private const PATH = '/api/v1/inquiries';

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
     * @return array{body:string, server:array<string,string>}
     */
    private function signedRequest(string $method, string $path, array $payload, ?string $idempotencyKey = null, ?string $timestamp = null): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp ??= (string) time();

        $canonical = strtoupper($method)."\n".$path."\n".$timestamp."\n".hash('sha256', $body);
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

        return ['body' => $body, 'server' => $server];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sendRequest(array $payload, ?string $idempotencyKey, ?string $timestamp = null, ?string $keyId = 'test-us812')
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp ??= (string) time();

        $canonical = "POST\n".self::PATH."\n".$timestamp."\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, self::SECRET);

        $server = [
            'HTTP_X_API_KEY' => $keyId,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $this->call('POST', self::PATH, [], [], [], $server, $body);
    }

    public function test_valid_signed_request_creates_full_record_set(): void
    {
        $this->siteAndCredential();

        $payload = [
            'name' => 'Иванов Иван',
            'phone' => '+7 (999) 123-45-67',
            'email' => 'Ivanov@Example.com',
            'message' => 'Хочу кольцо',
            'external_id' => 'ext-001',
            'utm' => ['source' => 'yandex', 'medium' => 'cpc', 'campaign' => 'ring'],
        ];

        $response = $this->sendRequest($payload, 'idem-key-1');

        $response->assertStatus(201);

        $this->assertCount(1, Customer::query()->get());
        $this->assertCount(1, Inquiry::query()->get());
        $this->assertCount(1, Touchpoint::query()->get());

        $inquiry = Inquiry::query()->firstOrFail();
        $this->assertSame(InquiryStatus::New, $inquiry->status);
        $this->assertSame('79991234567', $inquiry->customer->phone_normalized);
        $this->assertSame('ivanov@example.com', $inquiry->customer->email_normalized);
        $this->assertCount(1, $inquiry->statusHistory()->get());
        $this->assertDatabaseCount('business_events', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->siteAndCredential();

        $body = json_encode(['phone' => '+79991234567']);
        $timestamp = (string) time();

        $response = $this->call('POST', self::PATH, [], [], [], [
            'HTTP_X_API_KEY' => 'test-us812',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => str_repeat('0', 64),
            'HTTP_IDEMPOTENCY_KEY' => 'idem-key-2',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->siteAndCredential();

        $response = $this->sendRequest(['phone' => '+79991234567'], 'idem-key-3', (string) (time() - 3600));

        $response->assertStatus(401)->assertJsonPath('error.code', 'stale_timestamp');
    }

    public function test_repeat_with_same_key_does_not_duplicate(): void
    {
        $this->siteAndCredential();

        $payload = [
            'name' => 'Петрова Мария',
            'phone' => '+79991112233',
            'message' => 'Серьги',
        ];

        $this->sendRequest($payload, 'idem-repeat')->assertStatus(201);
        $this->sendRequest($payload, 'idem-repeat')->assertStatus(201);

        $this->assertCount(1, Inquiry::query()->get());
        $this->assertCount(1, Customer::query()->get());
        $this->assertCount(1, Touchpoint::query()->get());
        $this->assertDatabaseCount('business_events', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_same_key_with_different_payload_returns_422(): void
    {
        $this->siteAndCredential();

        $this->sendRequest(['phone' => '+79991112233'], 'idem-collision')->assertStatus(201);

        $response = $this->sendRequest(['phone' => '+79994445566'], 'idem-collision');

        $response->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reuse');
    }

    public function test_matching_conflict_is_not_auto_merged(): void
    {
        $this->siteAndCredential();

        Customer::query()->create([
            'name' => 'Клиент А',
            'phone_normalized' => '79991234567',
            'phone_raw' => '+7 (999) 123-45-67',
            'is_merged' => false,
        ]);

        Customer::query()->create([
            'name' => 'Клиент Б',
            'email_normalized' => 'conflict@example.com',
            'email_raw' => 'conflict@example.com',
            'is_merged' => false,
        ]);

        $response = $this->sendRequest([
            'name' => 'Кто-то',
            'phone' => '+7 (999) 123-45-67',
            'email' => 'conflict@example.com',
        ], 'idem-conflict');

        $response->assertStatus(201);

        $customerA = Customer::query()->where('phone_normalized', '79991234567')->firstOrFail();
        $this->assertNull($customerA->email_normalized, 'Контакт email не должен быть перенесён в карточку A');

        $customerB = Customer::query()->where('email_normalized', 'conflict@example.com')->firstOrFail();
        $this->assertNull($customerB->phone_normalized, 'Контакт phone не должен быть перенесён в карточку B');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_matching_conflict']);
    }

    public function test_unknown_credential_is_rejected(): void
    {
        $this->siteAndCredential();

        $response = $this->sendRequest(['phone' => '+79991234567'], 'idem-unknown', null, 'nope');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unknown_credential');
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $this->siteAndCredential();

        $response = $this->sendRequest(['phone' => '+79991234567'], null);

        $response->assertStatus(422)->assertJsonPath('error.code', 'missing_idempotency_key');
    }

    public function test_second_call_matches_existing_customer(): void
    {
        $this->siteAndCredential();

        $this->sendRequest(['name' => 'А', 'phone' => '+79991112233'], 'idem-m1')->assertStatus(201);
        $this->sendRequest(['name' => 'А', 'phone' => '8 999 111 22 33'], 'idem-m2')->assertStatus(201);

        $this->assertCount(1, Customer::query()->get());
        $this->assertCount(2, Inquiry::query()->get());
    }
}
