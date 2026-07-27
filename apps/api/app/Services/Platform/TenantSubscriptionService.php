<?php

namespace App\Services\Platform;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Audit\AuditLogger;
use App\Services\Usage\CommercialEntitlementService;
use App\Services\Usage\SubscriptionPeriodService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ciclo de vida comercial do tenant: TRIAL → ACTIVE → PAST_DUE → SUSPENDED → CANCELED.
 * Não apaga ledger, auditoria, snapshots nem evidências fiscais.
 * Período comercial = aniversário da assinatura (não mês-calendário).
 */
final class TenantSubscriptionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubscriptionPeriodService $periods,
        private readonly CommercialEntitlementService $commercial,
    ) {}

    /**
     * Garante assinatura corrente do tenant (idempotente).
     */
    public function ensureForTenant(
        Tenant $tenant,
        SubscriptionPlan $plan = SubscriptionPlan::Professional,
        SubscriptionStatus $status = SubscriptionStatus::Active,
    ): TenantSubscription {
        $existing = TenantSubscription::query()->where('tenant_id', $tenant->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->create($tenant, $plan, $status);
    }

    public function create(
        Tenant $tenant,
        SubscriptionPlan $plan = SubscriptionPlan::Professional,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?int $trialDays = null,
    ): TenantSubscription {
        if (TenantSubscription::query()->where('tenant_id', $tenant->id)->exists()) {
            throw new RuntimeException('Escritório já possui assinatura.');
        }

        $defaults = $this->commercial->commercialDefaultsForPlan($plan);
        $now = now();
        [$periodStart, $periodEnd] = $this->periods->initialBounds($now->toImmutable());

        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan' => $plan,
            'status' => $status,
            'trial_ends_at' => $status === SubscriptionStatus::Trial
                ? $now->copy()->addDays($trialDays ?? 14)
                : null,
            'starts_at' => $now,
            'ends_at' => null,
            // Aniversário comercial — NÃO startOfMonth/endOfMonth.
            'current_period_starts_at' => $periodStart,
            'current_period_ends_at' => $periodEnd,
            'monthly_api_quota' => $defaults['monthly_api_quota'],
            'commercial_monitor_units' => $defaults['commercial_monitor_units'],
            'max_clients' => $defaults['max_clients'],
            'negotiated_client_limit' => null,
            'max_users' => $defaults['max_users'],
            'limits' => $defaults['limits'],
        ]);

        $this->audit->record(
            action: 'tenant_subscription.created',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'plan' => $plan->value,
                'status' => $status->value,
            ],
            tenantId: $tenant->id,
        );

        return $subscription;
    }

    public function activate(TenantSubscription $subscription): TenantSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Active, [
            'starts_at' => $subscription->starts_at ?? now(),
            'ends_at' => null,
            'trial_ends_at' => $subscription->trial_ends_at,
        ]);
    }

    public function markPastDue(TenantSubscription $subscription): TenantSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::PastDue);
    }

    public function suspend(TenantSubscription $subscription, ?string $reason = null): TenantSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Suspended, notes: $reason);
    }

    public function cancel(TenantSubscription $subscription, ?string $reason = null): TenantSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Canceled, [
            'ends_at' => now(),
        ], $reason);
    }

    public function resume(TenantSubscription $subscription): TenantSubscription
    {
        if ($subscription->status === SubscriptionStatus::Canceled) {
            throw new InvalidArgumentException('Assinatura cancelada não pode ser reativada; crie nova se necessário.');
        }

        if (! in_array($subscription->status, [SubscriptionStatus::Suspended, SubscriptionStatus::PastDue], true)) {
            throw new InvalidArgumentException('Somente PAST_DUE ou SUSPENDED podem retomar para ACTIVE.');
        }

        return $this->transition($subscription, SubscriptionStatus::Active, [
            'ends_at' => null,
        ]);
    }

    public function changePlan(TenantSubscription $subscription, SubscriptionPlan $plan): TenantSubscription
    {
        if ($subscription->status === SubscriptionStatus::Canceled) {
            throw new InvalidArgumentException('Não é possível alterar plano de assinatura cancelada.');
        }

        $defaults = $this->commercial->commercialDefaultsForPlan($plan);
        $from = $subscription->plan->value;

        // Troca de plano NÃO recria inaugural nem limpa limite negociado.
        $subscription->fill([
            'plan' => $plan,
            'monthly_api_quota' => $defaults['monthly_api_quota'],
            'commercial_monitor_units' => $defaults['commercial_monitor_units'],
            'max_clients' => $defaults['max_clients'],
            'max_users' => $defaults['max_users'],
            'limits' => array_merge($subscription->limits ?? [], $defaults['limits']),
        ]);
        $subscription->save();

        $this->audit->record(
            action: 'tenant_subscription.plan_changed',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from_plan' => $from,
                'to_plan' => $plan->value,
                'status' => $subscription->status->value,
                'commercial_monitor_units' => $defaults['commercial_monitor_units'],
                'negotiated_client_limit' => $subscription->negotiated_client_limit,
            ],
            tenantId: $subscription->tenant_id,
        );

        return $subscription->refresh();
    }

    /**
     * Garante que o período corrente cobre $at (renovação por aniversário, sem rollover).
     */
    public function ensureCurrentPeriod(TenantSubscription $subscription, mixed $at = null): TenantSubscription
    {
        return $this->periods->ensureCurrent($subscription, $at);
    }

    /**
     * Limite negociado >200 — somente PLATFORM_ADMIN (via API de plataforma).
     */
    public function setNegotiatedClientLimit(
        TenantSubscription $subscription,
        int $limit,
        ?int $actorUserId = null,
    ): TenantSubscription {
        return $this->commercial->setNegotiatedClientLimit($subscription, $limit, $actorUserId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        TenantSubscription $subscription,
        SubscriptionStatus $to,
        array $attributes = [],
        ?string $notes = null,
    ): TenantSubscription {
        $from = $subscription->status;

        if ($from === $to) {
            return $subscription;
        }

        $this->assertTransitionAllowed($from, $to);

        $subscription->fill(array_merge($attributes, [
            'status' => $to,
        ]));

        if ($notes !== null && $notes !== '') {
            $subscription->notes = trim(($subscription->notes ? $subscription->notes."\n" : '').$notes);
        }

        $subscription->save();

        $this->audit->record(
            action: 'tenant_subscription.status_changed',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from_status' => $from->value,
                'to_status' => $to->value,
            ],
            tenantId: $subscription->tenant_id,
        );

        return $subscription->refresh();
    }

    private function assertTransitionAllowed(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        // Matriz mínima do ciclo de vida comercial.
        $allowed = match ($from) {
            SubscriptionStatus::PendingActivation => [
                SubscriptionStatus::Active,
                SubscriptionStatus::Canceled,
            ],
            SubscriptionStatus::Trial => [
                SubscriptionStatus::Active,
                SubscriptionStatus::PastDue,
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Canceled,
            ],
            SubscriptionStatus::Active => [
                SubscriptionStatus::PastDue,
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Canceled,
            ],
            SubscriptionStatus::PastDue => [
                SubscriptionStatus::Active,
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Canceled,
            ],
            SubscriptionStatus::Suspended => [
                SubscriptionStatus::Active,
                SubscriptionStatus::Canceled,
            ],
            SubscriptionStatus::Canceled => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                "Transição de assinatura inválida: {$from->value} → {$to->value}."
            );
        }
    }
}
