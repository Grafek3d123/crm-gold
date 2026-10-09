<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_guests_to_dashboard_then_login(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_root_redirects_authenticated_users_to_dashboard(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@crm.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }
}
