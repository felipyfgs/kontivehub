<?php

namespace App\Services\Serpro;

use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Integra\TenantSerproAuthorizationService;
use RuntimeException;

/**
 * Seleção explícita de Tenant real + ambiente, consentimento e papéis para
 * certificado/Termo/token. Tenant demo não usa endpoint real.
 */
final class SerproProductionOnboardingGuard
{
    public function __construct(
        private readonly TenantSerproAuthorizationService $authorizations,
    ) {}

    public function isDemoTenant(Tenant $tenant): bool
    {
        $demoSlug = strtolower((string) config('fiscal_demo.tenant_slug', 'demo'));
        $slug = strtolower((string) $tenant->slug);

        if ($slug === $demoSlug || str_contains($slug, 'demo')) {
            return true;
        }

        $seg = strtoupper((string) ($tenant->serpro_segregation_class ?? ''));

        return $seg === SerproDataSegregationClass::Demo->value;
    }

    /**
     * Bloqueia endpoint/driver real para Tenant demo ou segregação não produtiva.
     */
    public function assertMayUseRealEndpoint(Tenant $tenant, SerproEnvironment $environment): void
    {
        if ($this->isDemoTenant($tenant)) {
            throw new RuntimeException(
                'Escritório demo/seed é inelegível para endpoint real SERPRO.'
            );
        }

        if ($environment === SerproEnvironment::Production) {
            $seg = strtoupper((string) ($tenant->serpro_segregation_class ?? ''));
            // Fail-closed: null/vazio não é elegível — exige classe Production explícita.
            if ($seg !== SerproDataSegregationClass::Production->value) {
                throw new RuntimeException(
                    $seg === ''
                        ? 'Tenant sem serpro_segregation_class=PRODUCTION não pode usar produção real.'
                        : 'Tenant com segregação '.$seg.' não pode usar produção real.'
                );
            }
        }
    }

    /**
     * Mutações sensíveis: certificado, Termo, token.
     *
     * @param  'certificate'|'termo'|'token'|'proxy_approve'  $purpose
     */
    public function assertSensitiveMutationAllowed(
        Tenant $tenant,
        User $user,
        SerproEnvironment $environment,
        string $purpose,
        bool $explicitConsent,
        bool $tenantExplicitlySelected = true,
    ): void {
        if (! $tenantExplicitlySelected) {
            throw new RuntimeException('Seleção explícita do Tenant é obrigatória.');
        }

        $this->assertMayUseRealEndpoint($tenant, $environment);

        $membership = $user->memberships()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->first();

        if ($membership === null || $membership->role !== TenantRole::TenantAdmin) {
            throw new RuntimeException('Somente Tenant ADMIN pode executar '.$purpose.'.');
        }

        $passwordGate = app(RecentPasswordConfirmationGate::class);
        if (! $passwordGate->isRecentlyConfirmed($user)) {
            throw new RuntimeException('Reconfirmação de senha recente é obrigatória para '.$purpose.'.');
        }

        if (! $explicitConsent) {
            throw new RuntimeException('Consentimento explícito de finalidade é obrigatório para '.$purpose.'.');
        }
    }

    /**
     * Confirma identidade do Tenant/autor/ambiente antes de material sensível.
     *
     * @return array{tenant_id: int, environment: string, author_identity_masked: string}
     */
    public function confirmIdentitySelection(
        Tenant $tenant,
        SerproEnvironment $environment,
        User $user,
        bool $confirmTenant,
        bool $confirmEnvironment,
        bool $confirmAuthor,
    ): array {
        if (! $confirmTenant || ! $confirmEnvironment) {
            throw new RuntimeException('Confirmação explícita de Tenant e ambiente é obrigatória.');
        }

        $this->assertMayUseRealEndpoint($tenant, $environment);

        $auth = $this->authorizations->getOrCreate($tenant, $environment);
        $author = (string) $auth->author_identity;
        if ($confirmAuthor && ($author === '' || $author === '00000000000000')) {
            throw new RuntimeException('Autor do pedido ainda não configurado para confirmação.');
        }

        return [
            'tenant_id' => (int) $tenant->id,
            'environment' => $environment->value,
            'author_identity_masked' => $this->mask($author),
            'confirmed_by_user_id' => (int) $user->id,
        ];
    }

    private function mask(string $value): string
    {
        $value = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 2).str_repeat('*', max(0, $len - 6)).substr($value, -4);
    }
}
