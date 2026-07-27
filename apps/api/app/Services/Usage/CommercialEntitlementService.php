<?php

namespace App\Services\Usage;

use App\Enums\SubscriptionPlan;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Entitlements comerciais de monitores (5/7/10) e carteira (100/150/200 + negociado >200).
 * NÃO altera monthly_api_quota / UsageBudgetGate (ledger técnico).
 * Sem crédito, top-up, rollover ou override de franquia de consultas.
 */
final class CommercialEntitlementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubscriptionPeriodService $periods,
    ) {}

    /**
     * @return array{
     *   plan: string,
     *   commercial_monitor_units: int,
     *   max_clients: int,
     *   negotiated_client_limit: int|null,
     *   effective_max_clients: int,
     *   monthly_api_quota: int|null,
     *   period_key: string,
     *   period_starts_at: string,
     *   period_ends_at: string
     * }
     */
    public function snapshot(TenantSubscription $subscription, CarbonImmutable|string|null $at = null): array
    {
        $period = $this->periods->resolve($subscription, $at);

        return [
            'plan' => $subscription->plan->value,
            'commercial_monitor_units' => $this->monitorUnits($subscription),
            'max_clients' => (int) ($subscription->max_clients ?? $subscription->plan->commercialMaxClients()),
            'negotiated_client_limit' => $subscription->negotiated_client_limit !== null
                ? (int) $subscription->negotiated_client_limit
                : null,
            'effective_max_clients' => $this->effectiveMaxClients($subscription),
            'monthly_api_quota' => $subscription->monthly_api_quota !== null
                ? (int) $subscription->monthly_api_quota
                : null,
            'period_key' => $period['period_key'],
            'period_starts_at' => $period['starts_at']->toIso8601String(),
            'period_ends_at' => $period['ends_at']->toIso8601String(),
        ];
    }

    public function monitorUnits(TenantSubscription $subscription): int
    {
        return $subscription->resolvedCommercialMonitorUnits();
    }

    public function effectiveMaxClients(TenantSubscription $subscription): int
    {
        return $subscription->effectiveCommercialMaxClients();
    }

    /**
     * Somente plataforma: limite negociado acima de 200, sem mudar plano nem unidades de consulta.
     */
    public function setNegotiatedClientLimit(
        TenantSubscription $subscription,
        int $limit,
        ?int $actorUserId = null,
    ): TenantSubscription {
        if ($limit <= 200) {
            throw new InvalidArgumentException(
                'Limite negociado de clientes deve ser superior a 200.'
            );
        }

        if ($subscription->status->value === 'CANCELED') {
            throw new InvalidArgumentException('Não é possível negociar limite de assinatura cancelada.');
        }

        $from = $subscription->negotiated_client_limit;
        $subscription->forceFill([
            'negotiated_client_limit' => $limit,
            // max_clients permanece o do plano; effective usa negotiated.
        ])->save();

        $this->audit->record(
            action: 'tenant_subscription.negotiated_client_limit_set',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from' => $from,
                'to' => $limit,
                'plan' => $subscription->plan->value,
                'commercial_monitor_units' => $this->monitorUnits($subscription),
                'actor_user_id' => $actorUserId,
            ],
            tenantId: $subscription->tenant_id,
        );

        return $subscription->refresh();
    }

    public function clearNegotiatedClientLimit(TenantSubscription $subscription, ?int $actorUserId = null): TenantSubscription
    {
        $from = $subscription->negotiated_client_limit;
        $subscription->forceFill(['negotiated_client_limit' => null])->save();

        $this->audit->record(
            action: 'tenant_subscription.negotiated_client_limit_cleared',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from' => $from,
                'plan' => $subscription->plan->value,
                'actor_user_id' => $actorUserId,
            ],
            tenantId: $subscription->tenant_id,
        );

        return $subscription->refresh();
    }

    public function countRootClients(int $tenantId): int
    {
        return (int) Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * @return array{allowed: bool, current: int, max: int, reason: string|null}
     */
    public function evaluateClientCreate(Tenant|int $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        $subscription = TenantSubscription::query()->where('tenant_id', $tenantId)->first();

        if ($subscription === null) {
            return [
                'allowed' => false,
                'current' => $this->countRootClients($tenantId),
                'max' => 0,
                'reason' => 'SUBSCRIPTION_MISSING',
            ];
        }

        $max = $this->effectiveMaxClients($subscription);
        $current = $this->countRootClients($tenantId);

        if ($current >= $max) {
            return [
                'allowed' => false,
                'current' => $current,
                'max' => $max,
                'reason' => 'MAX_CLIENTS_REACHED',
            ];
        }

        return [
            'allowed' => true,
            'current' => $current,
            'max' => $max,
            'reason' => null,
        ];
    }

    /**
     * @throws RuntimeException quando acima do máximo
     */
    public function assertCanCreateClient(Tenant|int $tenant): void
    {
        $eval = $this->evaluateClientCreate($tenant);
        if ($eval['allowed']) {
            return;
        }

        throw new RuntimeException(match ($eval['reason']) {
            'SUBSCRIPTION_MISSING' => 'Escritório sem assinatura ativa; cadastro de clientes bloqueado.',
            default => sprintf(
                'Limite de clientes do plano atingido (%d/%d).',
                $eval['current'],
                $eval['max'],
            ),
        });
    }

    /**
     * Defaults comerciais ao criar/trocar plano (preserva monthly_api_quota técnico do plan.defaultLimits).
     *
     * @return array{
     *   commercial_monitor_units: int,
     *   max_clients: int,
     *   monthly_api_quota: int,
     *   max_users: int,
     *   limits: array<string, mixed>
     * }
     */
    public function commercialDefaultsForPlan(SubscriptionPlan $plan): array
    {
        $technical = $plan->defaultLimits();
        $units = $plan->commercialMonitorUnits();
        $maxClients = $plan->commercialMaxClients();

        return [
            'commercial_monitor_units' => $units,
            'max_clients' => $maxClients,
            'monthly_api_quota' => $technical['monthly_api_quota'],
            'max_users' => $technical['max_users'],
            'limits' => array_merge($technical, [
                'max_clients' => $maxClients,
                'commercial_monitor_units' => $units,
                'commercial_max_clients' => $maxClients,
            ]),
        ];
    }
}
