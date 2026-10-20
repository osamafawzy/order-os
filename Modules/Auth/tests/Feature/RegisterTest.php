<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];
    }

    public function test_registering_creates_the_user_and_returns_a_working_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'token_type',
                    'expires_in',
                    'user' => ['id', 'name', 'email', 'created_at'],
                ],
            ])
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', config('jwt.ttl') * 60)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $this->assertTrue(Hash::check('secret123', $user->password), 'Password must be stored hashed.');
        $this->assertNotSame('secret123', $user->password);

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_email_is_normalised_to_lower_case(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => '  Ada@Example.COM ']))
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com');
    }

    public function test_registration_dispatches_the_registered_event(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        Event::assertDispatched(Registered::class, fn (Registered $e): bool => $e->user->email === 'ada@example.com');
    }

    public function test_duplicate_email_is_refused_case_insensitively(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'ADA@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'An account with this email address already exists.']);

        $this->assertSame(1, User::query()->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['name' => null], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'email missing' => [['email' => null], 'email'],
            'email malformed' => [['email' => 'not-an-email'], 'email'],
            'password missing' => [['password' => null, 'password_confirmation' => null], 'password'],
            'password too short' => [['password' => 'abc12', 'password_confirmation' => 'abc12'], 'password'],
            'password without a number' => [['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'], 'password'],
            'password without a letter' => [['password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'different1'], 'password'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_registration_is_rejected(array $overrides, string $field): void
    {
        $this->postJson('/api/v1/auth/register', array_filter(
            $this->payload($overrides),
            static fn ($value): bool => $value !== null,
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, User::query()->count());
    }
}
