<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Starter = 'STARTER';
    case Professional = 'PROFESSIONAL';
    case Enterprise = 'ENTERPRISE';

    /**
     * Defaults técnicos/SaaS (orçamento de chamadas API + limites legados).
     * monthly_api_quota alimenta UsageBudgetGate — não é franquia comercial de monitor.
     *
     * @return array{monthly_api_quota: int, max_clients: int, max_users: int}
     */
    public function defaultLimits(): array
    {
        // max_clients = franquia comercial de carteira (100/150/200).
        // monthly_api_quota = orçamento técnico de chamadas (UsageBudgetGate) — separado.
        return match ($this) {
            self::Starter => [
                'monthly_api_quota' => 1_000,
                'max_clients' => 100,
                'max_users' => 5,
            ],
            self::Professional => [
                'monthly_api_quota' => 10_000,
                'max_clients' => 150,
                'max_users' => 25,
            ],
            self::Enterprise => [
                'monthly_api_quota' => 100_000,
                'max_clients' => 200,
                'max_users' => 200,
            ],
        };
    }

    /**
     * Unidades comerciais por cliente + monitor + período da assinatura.
     * Starter 5 / Professional 7 / Enterprise 10.
     */
    public function commercialMonitorUnits(): int
    {
        return match ($this) {
            self::Starter => 5,
            self::Professional => 7,
            self::Enterprise => 10,
        };
    }

    /**
     * Máximo padrão de clientes do plano (franquia comercial de carteira).
     * Override negociado acima de 200 fica em office_subscriptions.negotiated_client_limit.
     */
    public function commercialMaxClients(): int
    {
        return match ($this) {
            self::Starter => 100,
            self::Professional => 150,
            self::Enterprise => 200,
        };
    }

    /**
     * Entitlements comerciais de monitores SERPRO (distintos de monthly_api_quota).
     *
     * @return array{commercial_monitor_units: int, commercial_max_clients: int}
     */
    public function commercialEntitlements(): array
    {
        return [
            'commercial_monitor_units' => $this->commercialMonitorUnits(),
            'commercial_max_clients' => $this->commercialMaxClients(),
        ];
    }
}
