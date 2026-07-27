<?php

namespace App\Services\Serpro;

use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateStatus;
use App\Enums\SerproFunctionalRoute;
use App\Models\SerproCredentialVersion;
use App\Models\SerproExternalGate;
use App\Models\Tenant;

/**
 * Gate fail-closed para egress faturável em produção.
 *
 * Bloqueia Consultar/Declarar/Emitir enquanto:
 * - kill switch ativo;
 * - drivers reais / fake clients em estado inválido;
 * - versão de credencial exposta não estiver RETIRED/COMPROMISED;
 * - Tenant demo/segregado tentar endpoint real.
 *
 * Gates documentais externos são informativos (snapshot/CLI) e não bloqueiam egress.
 */
final class SerproProductionEgressGate
{
    public function __construct(
        private readonly SerproKillSwitchService $killSwitch,
    ) {}

    /**
     * @return array{
     *   allowed: bool,
     *   code: string|null,
     *   message: string|null,
     *   checks: list<array{id: string, ok: bool, detail: string}>
     * }
     */
    public function evaluateBillableEgress(
        ?SerproFunctionalRoute $route = null,
        ?Tenant $tenant = null,
        ?SerproEnvironment $environment = null,
    ): array {
        $environment ??= SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;

        $checks = [];
        $fail = function (string $id, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'ok' => false, 'detail' => $detail];
        };
        $pass = function (string $id, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'ok' => true, 'detail' => $detail];
        };

        if ($this->killSwitch->isGlobalActive()) {
            $fail('kill_switch', 'Kill switch global SERPRO ativo.');
        } else {
            $pass('kill_switch', 'Kill switch global inativo.');
        }

        if ($route !== null && $route->isNonBillableByRoute()) {
            $pass('route_class', "Rota {$route->value} não é faturável por definição oficial.");
        } else {
            $routeLabel = $route?->value ?? 'UNKNOWN';
            $pass('route_class', "Rota {$routeLabel} sujeita a regras de egress faturável.");
        }

        $exposedBlocking = $this->exposedCredentialsBlockingEgress($environment);
        if ($exposedBlocking !== []) {
            $ids = implode(',', array_map(fn (SerproCredentialVersion $v) => (string) $v->id, $exposedBlocking));
            $fail(
                'exposed_credentials',
                "Versão(ões) de credencial exposta(s) ainda não RETIRED/COMPROMISED: {$ids}."
            );
        } else {
            $pass('exposed_credentials', 'Nenhuma versão exposta bloqueia egress faturável.');
        }

        if ($tenant !== null) {
            $seg = $tenant->serpro_segregation_class
                ?? ($this->isDemoTenant($tenant) ? SerproDataSegregationClass::Demo->value : null);
            $segNormalized = $seg !== null ? strtoupper((string) $seg) : '';
            // Fail-closed em PRODUCTION: exige classe Production explícita (null/vazio bloqueia).
            if ($environment === SerproEnvironment::Production) {
                if ($segNormalized !== SerproDataSegregationClass::Production->value) {
                    $label = $segNormalized === '' ? 'unset' : $segNormalized;
                    $fail('tenant_segregation', "Tenant segregado como {$label}; endpoint real/faturável bloqueado.");
                } else {
                    $pass('tenant_segregation', 'Tenant elegível (PRODUCTION).');
                }
            } elseif ($segNormalized !== '' && $segNormalized !== SerproDataSegregationClass::Production->value) {
                $fail('tenant_segregation', "Tenant segregado como {$segNormalized}; endpoint real/faturável bloqueado.");
            } else {
                $pass('tenant_segregation', 'Tenant elegível (não demo/shadow).');
            }
        }

        // Gates documentais são tracking ops/CLI — não bloqueiam egress na console simplificada.
        if ($route !== null && $route->isNonBillableByRoute()) {
            $pass('external_gates', 'Rota não faturável: gates documentais não aplicados ao egress.');
        } else {
            $pass('external_gates', 'Gates documentais não bloqueiam egress (fluxo admin simplificado).');
        }

        $failed = array_values(array_filter($checks, fn (array $c) => ! $c['ok']));
        if ($failed === []) {
            return [
                'allowed' => true,
                'code' => null,
                'message' => null,
                'checks' => $checks,
            ];
        }

        $first = $failed[0];

        return [
            'allowed' => false,
            'code' => strtoupper($first['id']),
            'message' => $first['detail'],
            'checks' => $checks,
        ];
    }

    /**
     * Avaliação read-only para prod-check / readiness (sem HTTP).
     *
     * @return array{
     *   ok: bool,
     *   environment: string,
     *   kill_switch: array{active: bool, source: string|null},
     *   drivers: array<string, string>,
     *   exposed_blocking_versions: list<array<string, mixed>>,
     *   external_gates_open: list<array<string, mixed>>,
     *   billable_egress: array{allowed: bool, code: string|null, message: string|null, checks: list<array{id: string, ok: bool, detail: string}>},
     *   issues: list<string>
     * }
     */
    public function prodCheckSnapshot(?SerproEnvironment $environment = null): array
    {
        $environment ??= SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;

        $drivers = is_array(config('serpro.capabilities'))
            ? array_map(fn ($v) => (string) $v, config('serpro.capabilities'))
            : [];

        $issues = [];
        $kill = $this->killSwitch->status();

        $realDrivers = array_keys(array_filter(
            $drivers,
            fn (string $v, string $k) => $k !== 'default' && strtolower($v) === 'real',
            ARRAY_FILTER_USE_BOTH
        ));
        if ($realDrivers !== [] && ! (bool) config('serpro.allow_real_drivers_in_prod_check', false)) {
            $issues[] = 'Drivers reais habilitados: '.implode(',', $realDrivers).' (default deve ser disabled).';
        }

        $exposed = $this->exposedCredentialsBlockingEgress($environment);
        $exposedSanitized = array_map(
            fn (SerproCredentialVersion $v) => $v->toSanitizedArray(),
            $exposed
        );
        if ($exposed !== []) {
            $issues[] = 'Credenciais expostas ainda não RETIRED/COMPROMISED bloqueiam egress faturável.';
        }

        $openGates = SerproExternalGate::query()
            ->get()
            ->filter(fn (SerproExternalGate $g) => $g->status !== SerproExternalGateStatus::Accepted
                && $g->status !== SerproExternalGateStatus::Waived)
            ->map->toSanitizedArray()
            ->values()
            ->all();

        $billable = $this->evaluateBillableEgress(
            route: SerproFunctionalRoute::Consultar,
            environment: $environment,
        );
        if (! $billable['allowed']) {
            $issues[] = 'Egress faturável bloqueado: '.($billable['message'] ?? $billable['code']);
        }

        return [
            'ok' => $issues === [],
            'environment' => $environment->value,
            'kill_switch' => $kill['global'],
            'drivers' => $drivers,
            'exposed_blocking_versions' => $exposedSanitized,
            'external_gates_open' => $openGates,
            'billable_egress' => $billable,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<SerproCredentialVersion>
     */
    public function exposedCredentialsBlockingEgress(SerproEnvironment $environment): array
    {
        return SerproCredentialVersion::query()
            ->where('environment', $environment->value)
            ->where('was_exposed', true)
            ->whereNotIn('status', [
                SerproCredentialVersionStatus::Retired->value,
                SerproCredentialVersionStatus::Compromised->value,
            ])
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function isDemoTenant(Tenant $tenant): bool
    {
        $slug = strtolower((string) $tenant->slug);
        $demoSlug = strtolower((string) config('fiscal_demo.tenant_slug', 'demo'));

        return $slug === $demoSlug || str_contains($slug, 'demo');
    }
}
