<?php

namespace Tests\Unit;

use App\Attribution\AttributionProjectionService;
use App\Models\Customer;
use App\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributionProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_first_and_last_touch(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Клиент',
            'phone_normalized' => '79991234567',
            'phone_raw' => '+7 (999) 123-45-67',
            'is_merged' => false,
        ]);

        $early = Touchpoint::query()->create([
            'customer_id' => $customer->id,
            'source_kind' => 'web_form',
            'utm_source' => 'yandex',
            'touched_at' => now()->subDays(3),
        ]);

        $middle = Touchpoint::query()->create([
            'customer_id' => $customer->id,
            'source_kind' => 'web_form',
            'utm_source' => 'google',
            'touched_at' => now()->subDay(),
        ]);

        $late = Touchpoint::query()->create([
            'customer_id' => $customer->id,
            'source_kind' => 'call',
            'touched_at' => now(),
        ]);

        $service = new AttributionProjectionService();
        $service->rebuildFor($customer);

        $customer->refresh();

        $this->assertSame($early->id, $customer->first_touchpoint_id);
        $this->assertSame($late->id, $customer->last_touchpoint_id);
        $this->assertSame('yandex', $service->firstTouch($customer)?->utm_source);
        $this->assertSame('call', $service->lastTouch($customer)?->source_kind);
    }

    public function test_projection_is_null_without_touchpoints(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Без касаний',
            'phone_normalized' => '79990001122',
            'is_merged' => false,
        ]);

        $service = new AttributionProjectionService();
        $service->rebuildFor($customer);

        $customer->refresh();

        $this->assertNull($customer->first_touchpoint_id);
        $this->assertNull($customer->last_touchpoint_id);
    }

    public function test_rebuild_all_updates_every_active_customer(): void
    {
        $a = Customer::query()->create(['name' => 'A', 'phone_normalized' => '79991111111', 'is_merged' => false]);
        $b = Customer::query()->create(['name' => 'B', 'phone_normalized' => '79992222222', 'is_merged' => false]);

        Touchpoint::query()->create(['customer_id' => $a->id, 'source_kind' => 'web_form', 'touched_at' => now()]);
        Touchpoint::query()->create(['customer_id' => $b->id, 'source_kind' => 'call', 'touched_at' => now()]);

        $service = new AttributionProjectionService();
        $count = $service->rebuildAll();

        $this->assertSame(2, $count);
        $this->assertNotNull($a->refresh()->first_touchpoint_id);
        $this->assertNotNull($b->refresh()->last_touchpoint_id);
    }
}
