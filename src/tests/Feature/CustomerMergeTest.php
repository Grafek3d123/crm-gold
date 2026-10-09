<?php

namespace Tests\Feature;

use App\Actions\Customers\CustomerMergeConflictException;
use App\Actions\Customers\MergeCustomersAction;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Inquiry;
use App\Models\Touchpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::query()->create([
            'name' => 'User',
            'email' => $role.'@crm.local',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeCustomer(string $phone): Customer
    {
        return Customer::query()->create([
            'name' => 'Client',
            'phone_normalized' => $phone,
            'phone_raw' => $phone,
            'is_merged' => false,
        ]);
    }

    public function test_admin_can_merge_customers(): void
    {
        $admin = $this->makeUser('admin');
        $winner = $this->makeCustomer('79991112233');
        $loser = $this->makeCustomer('79994445566');

        $inquiry = Inquiry::query()->create([
            'customer_id' => $loser->id,
            'source_kind' => 'call',
            'status' => 'new',
            'phone_raw' => '79994445566',
        ]);

        $touchpoint = Touchpoint::query()->create([
            'customer_id' => $loser->id,
            'touched_at' => now(),
            'source_kind' => 'ad',
        ]);

        $identity = CustomerIdentity::query()->create([
            'customer_id' => $loser->id,
            'provider' => 'phone',
            'external_key' => '79994445566',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('customers.merge', $winner), [
                'loser_id' => $loser->id,
                'reason' => 'один клиент',
            ]);

        $response->assertRedirect(route('customers.show', $winner));

        $this->assertDatabaseHas('customers', [
            'id' => $winner->id,
            'is_merged' => false,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $loser->id,
            'is_merged' => true,
        ]);
        $this->assertSame($winner->id, $inquiry->fresh()->customer_id);
        $this->assertSame($winner->id, $touchpoint->fresh()->customer_id);
        $this->assertSame($winner->id, $identity->fresh()->customer_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer_merged',
            'entity_type' => Customer::class,
            'entity_id' => $winner->id,
        ]);
    }

    public function test_manager_cannot_merge_customers(): void
    {
        $manager = $this->makeUser('manager');
        $winner = $this->makeCustomer('79991112233');
        $loser = $this->makeCustomer('79994445566');

        $response = $this->actingAs($manager)
            ->post(route('customers.merge', $winner), [
                'loser_id' => $loser->id,
                'reason' => 'nope',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('customers', ['id' => $loser->id, 'is_merged' => false]);
    }

    public function test_cannot_merge_into_self(): void
    {
        $admin = $this->makeUser('admin');
        $winner = $this->makeCustomer('79991112233');

        $response = $this->actingAs($admin)
            ->post(route('customers.merge', $winner), [
                'loser_id' => $winner->id,
                'reason' => 'self',
            ]);

        $response->assertSessionHasErrors(['loser_id']);
    }

    public function test_cannot_merge_already_merged_customer(): void
    {
        $admin = $this->makeUser('admin');
        $winner = $this->makeCustomer('79991112233');
        $loser = $this->makeCustomer('79994445566');
        $loser->update(['is_merged' => true]);

        $response = $this->actingAs($admin)
            ->post(route('customers.merge', $winner), [
                'loser_id' => $loser->id,
                'reason' => 'merged',
            ]);

        $response->assertSessionHasErrors(['loser_id']);
    }

    }
