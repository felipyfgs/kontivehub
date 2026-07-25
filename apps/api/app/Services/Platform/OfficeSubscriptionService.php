<?php

namespace App\Services\Platform;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Office;
use App\Models\OfficeSubscription;
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
final class OfficeSubscriptionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubscriptionPeriodService $periods,
        private readonly CommercialEntitlementService $commercial,
    ) {}

    /**
     * Garante assinatura corrente do office (idempotente).
     */
    public function ensureForOffice(
        Office $office,
        SubscriptionPlan $plan = SubscriptionPlan::Professional,
        SubscriptionStatus $status = SubscriptionStatus::Active,
    ): OfficeSubscription {
        $existing = OfficeSubscription::query()->where('office_id', $office->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->create($office, $plan, $status);
    }

    public function create(
        Office $office,
        SubscriptionPlan $plan = SubscriptionPlan::Professional,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?int $trialDays = null,
    ): OfficeSubscription {
        if (OfficeSubscription::query()->where('office_id', $office->id)->exists()) {
            throw new RuntimeException('Escritório já possui assinatura.');
        }

        $defaults = $this->commercial->commercialDefaultsForPlan($plan);
        $now = now();
        [$periodStart, $periodEnd] = $this->periods->initialBounds($now->toImmutable());

        $subscription = OfficeSubscription::query()->create([
            'office_id' => $office->id,
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
            action: 'office_subscription.created',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'plan' => $plan->value,
                'status' => $status->value,
            ],
            officeId: $office->id,
        );

        return $subscription;
    }

    public function activate(OfficeSubscription $subscription): OfficeSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Active, [
            'starts_at' => $subscription->starts_at ?? now(),
            'ends_at' => null,
            'trial_ends_at' => $subscription->trial_ends_at,
        ]);
    }

    public function markPastDue(OfficeSubscription $subscription): OfficeSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::PastDue);
    }

    public function suspend(OfficeSubscription $subscription, ?string $reason = null): OfficeSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Suspended, notes: $reason);
    }

    public function cancel(OfficeSubscription $subscription, ?string $reason = null): OfficeSubscription
    {
        return $this->transition($subscription, SubscriptionStatus::Canceled, [
            'ends_at' => now(),
        ], $reason);
    }

    public function resume(OfficeSubscription $subscription): OfficeSubscription
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

    public function changePlan(OfficeSubscription $subscription, SubscriptionPlan $plan): OfficeSubscription
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
            action: 'office_subscription.plan_changed',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from_plan' => $from,
                'to_plan' => $plan->value,
                'status' => $subscription->status->value,
                'commercial_monitor_units' => $defaults['commercial_monitor_units'],
                'negotiated_client_limit' => $subscription->negotiated_client_limit,
            ],
            officeId: $subscription->office_id,
        );

        return $subscription->refresh();
    }

    /**
     * Garante que o período corrente cobre $at (renovação por aniversário, sem rollover).
     */
    public function ensureCurrentPeriod(OfficeSubscription $subscription, mixed $at = null): OfficeSubscription
    {
        return $this->periods->ensureCurrent($subscription, $at);
    }

    /**
     * Limite negociado >200 — somente PLATFORM_ADMIN (via API de plataforma).
     */
    public function setNegotiatedClientLimit(
        OfficeSubscription $subscription,
        int $limit,
        ?int $actorUserId = null,
    ): OfficeSubscription {
        return $this->commercial->setNegotiatedClientLimit($subscription, $limit, $actorUserId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        OfficeSubscription $subscription,
        SubscriptionStatus $to,
        array $attributes = [],
        ?string $notes = null,
    ): OfficeSubscription {
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
            action: 'office_subscription.status_changed',
            result: 'SUCCESS',
            subject: $subscription,
            context: [
                'from_status' => $from->value,
                'to_status' => $to->value,
            ],
            officeId: $subscription->office_id,
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
