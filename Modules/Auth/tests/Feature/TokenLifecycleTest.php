<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * Issue, use, refresh and revoke — with real tokens through the real guard.
 */
class TokenLifecycleTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    public function test_me_returns_the_token_owner(): void
    {
        $user = $this->actingAsJwt();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
            ]]);
    }

    public function test_each_token_identifies_its_own_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceToken = $this->issueJwt($alice);
        $bobToken = $this->issueJwt($bob);

        $this->withToken($aliceToken)->getJson('/api/v1/auth/me')->assertJsonPath('data.id', $alice->id);
        $this->withToken($bobToken)->getJson('/api/v1/auth/me')->assertJsonPath('data.id', $bob->id);
    }

    public function test_requests_without_a_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_a_malformed_token_is_refused(): void
    {
        $this->withToken('not.a.jwt')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_token_with_a_tampered_signature_is_refused(): void
    {
        $this->actingAsJwt();

        [$header, $payload] = explode('.', (string) $this->jwt);

        $this->withToken($header.'.'.$payload.'.'.rtrim(strtr(base64_encode('forged'), '+/', '-_'), '='))
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_a_token_for_a_deleted_user_is_refused(): void
    {
        $user = $this->actingAsJwt();
        $user->delete();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->actingAsJwt();

        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_issues_a_new_token_and_revokes_the_old_one(): void
    {
        $user = $this->actingAsJwt();
        $old = (string) $this->jwt;

        // Distinct "iat" so the two tokens cannot be byte-identical.
        $this->travel(1)->seconds();

        $response = $this->postJson('/api/v1/auth/refresh');

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'bearer');

        $new = $response->json('data.access_token');
        $this->assertIsString($new);
        $this->assertNotSame($old, $new);

        $this->withToken($new)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // A revoked token cannot be used to mint another one either.
        $this->withToken($old)->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->actingAsJwt();

        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_logout_only_revokes_the_token_it_was_called_with(): void
    {
        $user = User::factory()->create();
        $first = $this->issueJwt($user);
        $second = $this->issueJwt($user);

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_and_refresh_require_a_token(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_tokens_carry_the_user_id_as_subject(): void
    {
        $user = $this->actingAsJwt();

        $this->assertSame((string) $user->id, (string) JWTAuth::setToken((string) $this->jwt)->getPayload()->get('sub'));
    }
}
