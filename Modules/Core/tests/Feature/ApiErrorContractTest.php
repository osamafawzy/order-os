<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * The response shape every module relies on: JSON always, `{message, code}`
 * for errors, Laravel's `{message, errors}` for validation.
 */
class ApiErrorContractTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    public function test_unknown_endpoint_is_a_json_404_with_a_route_code(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'The requested endpoint does not exist.',
                'code' => 'route_not_found',
            ]);
    }

    public function test_wrong_http_method_is_a_json_405(): void
    {
        $this->actingAsJwt();

        $this->deleteJson('/api/v1/payments')
            ->assertStatus(405)
            ->assertJson(['code' => 'method_not_allowed']);
    }

    public function test_missing_token_is_a_json_401_with_a_stable_code(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated. Send a valid bearer token in the Authorization header.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        // A plain form post, as curl sends by default. Without the
        // ForceJsonResponse middleware this would be a 302 to "/".
        $response = $this->post('/api/v1/auth/login', []);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }

    public function test_domain_errors_omit_context_when_there_is_none(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1'])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'These credentials do not match our records.',
                'code' => 'invalid_credentials',
            ]);
    }

    public function test_rate_limited_requests_get_the_error_shape_and_keep_retry_headers(): void
    {
        config(['auth.login_attempts_per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x'])->assertUnauthorized();
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x']);

        $response->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests. Wait for the time given in the Retry-After header and try again.',
                'code' => 'too_many_requests',
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_the_authenticated_api_throttle_also_answers_429(): void
    {
        config(['core.rate_limit_per_minute' => 2]);
        $this->actingAsJwt();

        $this->getJson('/api/v1/orders')->assertOk();
        $this->getJson('/api/v1/orders')->assertOk();

        $this->getJson('/api/v1/orders')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_unexpected_exceptions_are_a_generic_500_outside_debug_mode(): void
    {
        config(['app.debug' => false]);
        $this->app['router']->get('api/v1/_test/explode', fn () => throw new \RuntimeException('SQLSTATE secret internals'));

        $response = $this->getJson('/api/v1/_test/explode');

        $response->assertStatus(500)->assertExactJson([
            'message' => 'Something went wrong on our side. The error has been logged.',
            'code' => 'server_error',
        ]);
        $this->assertStringNotContainsString('secret internals', (string) $response->getContent());
    }

    public function test_unexpected_exceptions_show_details_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->app['router']->get('api/v1/_test/explode', fn () => throw new \RuntimeException('visible in debug'));

        $this->getJson('/api/v1/_test/explode')
            ->assertStatus(500)
            ->assertJsonPath('message', 'visible in debug')
            ->assertJsonMissingPath('code');
    }

    public function test_the_generic_handler_does_not_swallow_http_or_validation_errors(): void
    {
        config(['app.debug' => false]);
        $this->app['router']->get('api/v1/_test/forbidden', fn () => abort(403, 'Nope'));

        $this->getJson('/api/v1/_test/forbidden')->assertForbidden()->assertJsonPath('message', 'Nope');
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_non_numeric_resource_id_does_not_match_a_route(): void
    {
        $this->actingAsJwt();

        $this->getJson('/api/v1/orders/abc')
            ->assertNotFound()
            ->assertJson(['code' => 'route_not_found']);
    }
}
