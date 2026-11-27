<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_can_log_in_with_email_case_and_whitespace_variations(): void
    {
        $user = User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => ' ANA@EXAMPLE.COM ',
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'cliente')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token']);
    }

    public function test_existing_user_can_log_in_with_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'name' => ' ana silva ',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_user_created_through_api_can_log_in_with_identifier_and_password(): void
    {
        $credentials = [
            'name' => 'Domingas Alice',
            'email' => 'domingas@example.com',
            'password' => 'senha1234',
        ];

        $registration = $this->postJson('/api/users', $credentials);
        $registration->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'role']])
            ->assertJsonPath('token_type', 'Bearer');

        $this->withToken($registration->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $credentials['email']);

        $this->postJson('/api/login', [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $credentials['email'])
            ->assertJsonStructure(['token']);
    }

    public function test_login_by_name_is_rejected_when_names_are_not_unique(): void
    {
        User::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana1@example.com',
            'password' => 'password',
        ]);
        User::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana2@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'Ana Silva',
            'password' => 'password',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Utilizador ou palavra-passe inválidos.',
            ]);
    }

    public function test_login_rejects_an_incorrect_password(): void
    {
        User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ana@example.com',
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertJson([
            'message' => 'Utilizador ou palavra-passe inválidos.',
            ]);
    }
}
