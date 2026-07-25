<?php

namespace App\Services\Serpro;

use App\Enums\OfficeRole;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Integra\OfficeSerproAuthorizationService;
use RuntimeException;

/**
 * Seleção explícita de Office real + ambiente, consentimento e papéis para
 * certificado/Termo/token. Office demo não usa endpoint real.
 */
final class SerproProductionOnboardingGuard
{
    public function __construct(
        private readonly OfficeSerproAuthorizationService $authorizations,
    ) {}

    public function isDemoOffice(Office $office): bool
    {
        $demoSlug = strtolower((string) config('fiscal_demo.office_slug', 'demo'));
        $slug = strtolower((string) $office->slug);

        if ($slug === $demoSlug || str_contains($slug, 'demo')) {
            return true;
        }

        $seg = strtoupper((string) ($office->serpro_segregation_class ?? ''));

        return $seg === SerproDataSegregationClass::Demo->value;
    }

    /**
     * Bloqueia endpoint/driver real para Office demo ou segregação não produtiva.
     */
    public function assertMayUseRealEndpoint(Office $office, SerproEnvironment $environment): void
    {
        if ($this->isDemoOffice($office)) {
            throw new RuntimeException(
                'Escritório demo/seed é inelegível para endpoint real SERPRO.'
            );
        }

        if ($environment === SerproEnvironment::Production) {
            $seg = strtoupper((string) ($office->serpro_segregation_class ?? ''));
            // Fail-closed: null/vazio não é elegível — exige classe Production explícita.
            if ($seg !== SerproDataSegregationClass::Production->value) {
                throw new RuntimeException(
                    $seg === ''
                        ? 'Office sem serpro_segregation_class=PRODUCTION não pode usar produção real.'
                        : 'Office com segregação '.$seg.' não pode usar produção real.'
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
        Office $office,
        User $user,
        SerproEnvironment $environment,
        string $purpose,
        bool $explicitConsent,
        bool $officeExplicitlySelected = true,
    ): void {
        if (! $officeExplicitlySelected) {
            throw new RuntimeException('Seleção explícita do Office é obrigatória.');
        }

        $this->assertMayUseRealEndpoint($office, $environment);

        $membership = $user->memberships()
            ->where('office_id', $office->id)
            ->where('is_active', true)
            ->first();

        if ($membership === null || $membership->role !== OfficeRole::Admin) {
            throw new RuntimeException('Somente Office ADMIN pode executar '.$purpose.'.');
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
     * Confirma identidade do Office/autor/ambiente antes de material sensível.
     *
     * @return array{office_id: int, environment: string, author_identity_masked: string}
     */
    public function confirmIdentitySelection(
        Office $office,
        SerproEnvironment $environment,
        User $user,
        bool $confirmOffice,
        bool $confirmEnvironment,
        bool $confirmAuthor,
    ): array {
        if (! $confirmOffice || ! $confirmEnvironment) {
            throw new RuntimeException('Confirmação explícita de Office e ambiente é obrigatória.');
        }

        $this->assertMayUseRealEndpoint($office, $environment);

        $auth = $this->authorizations->getOrCreate($office, $environment);
        $author = (string) $auth->author_identity;
        if ($confirmAuthor && ($author === '' || $author === '00000000000000')) {
            throw new RuntimeException('Autor do pedido ainda não configurado para confirmação.');
        }

        return [
            'office_id' => (int) $office->id,
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
