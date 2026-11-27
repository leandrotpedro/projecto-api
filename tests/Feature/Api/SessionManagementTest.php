<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_only_own_sessions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownToken = $user->createToken('own-session');
        $otherToken = $otherUser->createToken('other-session');

        $response = $this->withToken($ownToken->plainTextToken)
            ->getJson('/api/sessions');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownToken->accessToken->id)
            ->assertJsonPath('0.name', 'own-session')
            ->assertJsonMissing(['id' => $otherToken->accessToken->id]);
    }

    public function test_authenticated_user_can_revoke_own_session_by_id(): void
    {
        $user = User::factory()->create();
        $authToken = $user->createToken('current-session');
        $sessionToRevoke = $user->createToken('other-session');

        $this->withToken($authToken->plainTextToken)
            ->deleteJson('/api/sessions/'.$sessionToRevoke->accessToken->id)
            ->assertOk()
            ->assertJson(['message' => 'Sessão terminada.']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $sessionToRevoke->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $authToken->accessToken->id,
        ]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $authToken = $user->createToken('current-session');
        $otherToken = $otherUser->createToken('other-session');

        $this->withToken($authToken->plainTextToken)
            ->deleteJson('/api/sessions/'.$otherToken->accessToken->id)
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
    }

    public function test_api_login_session_remains_valid_until_logout(): void
    {
        $seller = User::factory()->create(['email' => 'seller@example.com']);
        $seller->forceFill(['role' => 'vendedor'])->save();

        $token = $this->postJson('/api/login', [
            'email' => 'seller@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.id', $seller->id)
            ->json('token');

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $seller->id);

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Sessão terminada.']);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }
}
