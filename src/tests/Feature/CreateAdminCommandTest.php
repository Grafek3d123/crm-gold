<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_explicit_password(): void
    {
        $this->artisan('crm:create-admin', [
            '--email' => 'first@crm.local',
            '--name' => 'First Admin',
            '--password' => 'LocalDevPass!2026x',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'first@crm.local')->firstOrFail();

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('LocalDevPass!2026x', $user->password));
    }

    public function test_generates_password_when_not_supplied(): void
    {
        $this->artisan('crm:create-admin', [
            '--email' => 'generated@crm.local',
            '--name' => 'Generated',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'generated@crm.local')->firstOrFail();

        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::isHashed($user->password));
    }

    public function test_rejects_duplicate_email(): void
    {
        User::query()->create([
            'name' => 'Existing',
            'email' => 'dupe@crm.local',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->artisan('crm:create-admin', [
            '--email' => 'dupe@crm.local',
            '--name' => 'Dupe',
            '--password' => 'LocalDevPass!2026x',
        ])->assertFailed();

        $this->assertCount(1, User::query()->where('email', 'dupe@crm.local')->get());
    }

    public function test_rejects_weak_password(): void
    {
        $this->artisan('crm:create-admin', [
            '--email' => 'weak@crm.local',
            '--name' => 'Weak',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
