<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The factory password is "password".
        $this->user = User::factory()->create(['email' => 'grace@example.com']);
    }

    public function test_valid_credentials_return_a_token_for_that_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.id', $this->user->id);

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'grace@example.com');
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => '  GRACE@Example.com ',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_wrong_password_is_refused(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissingPath('data');
    }

    public function test_unknown_email_gets_the_same_answer_as_a_wrong_password(): void
    {
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<int, string>}> */
    public static function invalidPayloads(): array
    {
        return [
            'empty body' => [[], ['email', 'password']],
            'email malformed' => [['email' => 'grace', 'password' => 'password'], ['email']],
            'password missing' => [['email' => 'grace@example.com'], ['password']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     */
    #[DataProvider('invalidPayloads')]
    public function test_malformed_login_is_a_validation_error(array $payload, array $fields): void
    {
        $this->postJson('/api/v1/auth/login', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($fields);
    }

    public function test_login_is_rate_limited_per_email(): void
    {
        config(['auth.login_attempts_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'grace@example.com', 'password' => 'nope'])
                ->assertUnauthorized();
        }

        // Even the right password is refused once the budget is spent.
        $this->postJson('/api/v1/auth/login', ['email' => 'grace@example.com', 'password' => 'password'])
            ->assertTooManyRequests();

        // Another address from the same client still has its own budget.
        $this->postJson('/api/v1/auth/login', ['email' => 'other@example.com', 'password' => 'nope'])
            ->assertUnauthorized();
    }
}
