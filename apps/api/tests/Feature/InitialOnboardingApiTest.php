<?php

namespace Tests\Feature;

use App\Models\PlatformMembership;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InitialOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'initial-onboarding-token-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'onboarding.enabled' => true,
            'onboarding.token' => self::TOKEN,
        ]);
    }

    public function test_status_and_completion_preserve_the_public_contract(): void
    {
        $this->getJson('/api/v1/onboarding/status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'available' => true,
                ],
            ]);

        $response = $this->postJson('/api/v1/onboarding', [
            'organization_name' => '  KontiveHub Contabilidade  ',
            'email' => 'OWNER@EXAMPLE.TEST',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'onboarding_token' => self::TOKEN,
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.redirect', '/admin/tenants/new')
            ->assertJsonPath('data.platform_organization_name', 'KontiveHub Contabilidade');

        $userId = (int) $response->json('data.user_id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'owner@example.test',
        ]);
        $this->assertDatabaseHas('platform_settings', [
            'id' => PlatformSetting::SINGLETON_ID,
            'organization_name' => 'KontiveHub Contabilidade',
            'onboarded_by_user_id' => $userId,
        ]);
        $this->assertSame(1, PlatformMembership::query()->count());
        $this->assertSame(1, User::query()->count());

        $this->getJson('/api/v1/onboarding/status')
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_completion_rejects_unknown_fields_and_invalid_token(): void
    {
        $payload = [
            'organization_name' => 'KontiveHub',
            'email' => 'owner@example.test',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'onboarding_token' => self::TOKEN,
        ];

        $this->postJson('/api/v1/onboarding', $payload + [
            'tenant_name' => 'Não deve ser criado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_name');

        $this->postJson('/api/v1/onboarding', [
            ...$payload,
            'onboarding_token' => str_repeat('x', 32),
        ])->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'message' => 'Onboarding não autorizado.',
                'code' => 'onboarding_not_authorized',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_memberships', 0);
    }

    public function test_production_completion_requires_https(): void
    {
        $this->app->instance('env', 'production');

        $this->postJson('http://api.kontivehub.com.br/api/v1/onboarding', [
            'organization_name' => 'KontiveHub',
            'email' => 'owner@example.test',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'onboarding_token' => self::TOKEN,
        ])->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'message' => 'Onboarding produtivo exige HTTPS.',
                'code' => 'https_required',
            ]);
    }
}
