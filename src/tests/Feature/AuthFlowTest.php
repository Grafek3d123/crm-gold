<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_flow(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@crm.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@crm.local',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => false,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'inactive@crm.local',
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/inquiries')->assertRedirect('/login');
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_dashboard_and_lists_render_without_errors(): void
    {
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Клиент',
            'phone_normalized' => '79991234567',
            'phone_raw' => '+7 (999) 123-45-67',
            'is_merged' => false,
        ]);

        Inquiry::query()->create([
            'customer_id' => $customer->id,
            'source_kind' => 'call',
            'status' => 'new',
            'phone_raw' => '+7 (999) 123-45-67',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/inquiries')->assertOk();
        $this->actingAs($user)->get('/customers')->assertOk();
        $this->actingAs($user)->get('/customers/'.$customer->id)->assertOk();
        $this->actingAs($user)->get('/inquiries/1')->assertOk();
        $this->actingAs($user)->get('/inquiries/create')->assertOk();
    }

    public function test_manual_inquiry_registration(): void
    {
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post('/inquiries', [
            'source_kind' => 'call',
            'name' => 'Звонивший',
            'phone' => '8 999 111 22 33',
            'message' => 'Нужна консультация',
        ]);

        $response->assertRedirect();

        $this->assertCount(1, Customer::query()->get());
        $this->assertCount(1, Inquiry::query()->get());
        $this->assertSame('79991112233', Customer::query()->firstOrFail()->phone_normalized);
        $this->assertNull(Inquiry::query()->firstOrFail()->site_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiry_created_manually']);
    }

    public function test_manual_inquiry_records_business_event_and_outbox(): void
    {
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post('/inquiries', [
            'source_kind' => 'call',
            'name' => 'Звонивший',
            'phone' => '8 999 111 22 33',
            'message' => 'Нужна консультация',
            'comment' => 'Перезвонить после 18:00',
        ])->assertRedirect();

        $this->assertDatabaseCount('business_events', 1);
        $this->assertDatabaseCount('outbox', 1);
        $this->assertDatabaseHas('business_events', ['event_type' => 'inquiry_received']);
        $this->assertDatabaseHas('outbox', ['event_type' => 'inquiry_received', 'status' => 'pending']);
        $this->assertSame('Перезвонить после 18:00', Inquiry::query()->firstOrFail()->comment);
    }

    public function test_manual_inquiry_with_one_click_query_normalizes_long_number(): void
    {
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post('/inquiries', [
            'source_kind' => 'call',
            'name' => 'Клиент',
            'phone' => '+79112412234576',
        ])->assertRedirect();

        $this->assertSame('72412234576', Customer::query()->firstOrFail()->phone_normalized);
    }

    public function test_status_transition_validation(): void
    {
        $user = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@crm.local',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Клиент',
            'phone_normalized' => '79991234567',
            'phone_raw' => '+7 (999) 123-45-67',
            'is_merged' => false,
        ]);

        $inquiry = Inquiry::query()->create([
            'customer_id' => $customer->id,
            'source_kind' => 'call',
            'status' => 'archived',
            'phone_raw' => '+7 (999) 123-45-67',
        ]);

        $response = $this->actingAs($user)->post('/inquiries/'.$inquiry->id.'/status', [
            'status' => 'new',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('archived', $inquiry->fresh()->status->value);
    }
}
