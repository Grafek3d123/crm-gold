<?php

namespace Tests\Feature;

use App\Actions\Customers\FindOrCreateCustomerAction;
use App\Actions\Inquiries\CreateManualInquiryAction;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Site;
use App\Support\InquirySourceKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class InquiryIngestionEdgeCaseTest extends TestCase
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
     */
    private function send(array $payload, string $idempotencyKey): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $path = self::PATH;

        $canonical = "POST\n{$path}\n{$timestamp}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, self::SECRET);

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_API_KEY' => 'test-us812',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_unknown_payload_fields_are_not_stored(): void
    {
        $this->siteAndCredential();

        $payload = [
            'phone' => '+79991234567',
            'name' => 'Клиент',
            'internal_username' => 'admin',
            'role' => 'admin',
            'password' => 'hunter2',
            'is_admin' => true,
        ];

        $this->send($payload, 'idem-fields')->assertStatus(201);

        $inquiry = Inquiry::query()->firstOrFail();

        // Неизвестные поля не должны попасть ни в метаданные, ни в обращение
        $this->assertSame([], $inquiry->metadata ?? []);
        $this->assertSame('Клиент', $inquiry->customer->name);
    }

    public function test_transaction_rollback_leaves_no_partial_records(): void
    {
        [$site] = $this->siteAndCredential();

        // Ломаем outbox, чтобы действие упало уже после создания customer/inquiry
        DB::statement('DROP TABLE outbox');

        try {
            $action = app(\App\Actions\Inquiries\IngestInquiryAction::class);

            $action->executeFromApi(['phone' => '+79991234567', 'name' => 'Клиент'], $site, 'hash');
        } catch (RuntimeException $e) {
            // ожидаемо
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('inquiries', 0);
        $this->assertDatabaseCount('touchpoints', 0);
        $this->assertDatabaseCount('business_events', 0);
        $this->assertDatabaseCount('idempotency', 0);
    }

    public function test_concurrent_creation_falls_back_to_existing_customer(): void
    {
        $action = app(FindOrCreateCustomerAction::class);

        $first = $action->execute('Первый', '+79991112233', null);
        $this->assertSame('79991112233', $first->customer->phone_normalized);

        $second = $action->execute('Второй', '8 999 111 22 33', null);

        $this->assertTrue($first->customer->is($second->customer->refresh()));
        $this->assertCount(1, Customer::query()->get());
    }

    public function test_concurrent_api_requests_do_not_duplicate_inquiries(): void
    {
        $this->siteAndCredential();

        $payload = ['phone' => '+79995556677', 'name' => 'Конкурентный'];
        $body = json_encode($payload);
        $timestamp = (string) time();
        $canonical = "POST\n".self::PATH."\n{$timestamp}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, self::SECRET);

        $server = [
            'HTTP_X_API_KEY' => 'test-us812',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];

        // Два разных idempotency-ключа — проверяем matching, а не идемпотентность
        foreach (['idem-c1', 'idem-c2'] as $key) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
            $this->call('POST', self::PATH, [], [], [], $server, $body)->assertStatus(201);
        }

        $this->assertCount(1, Customer::query()->get());
        $this->assertCount(2, Inquiry::query()->get());
        // Каждое обращение породило своё бизнес-событие в outbox
        $this->assertCount(2, DB::table('business_events')->get());
        $this->assertCount(2, DB::table('outbox')->get());
    }

    public function test_manual_inquiry_requires_contact(): void
    {
        $this->expectException(RuntimeException::class);

        $action = app(CreateManualInquiryAction::class);
        $action->execute(['source_kind' => 'call', 'name' => 'Без контактов'], InquirySourceKind::Call);
    }
}
