<?php

namespace App\Services\Operations\Inbox;

use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\TaxProxyPower;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Usage\TenantUsageQueryService;
use Illuminate\Support\Collection;

/**
 * SERPRO (termo/token/auth), procurações, disponibilidade de fonte e uso/franquia.
 */
final class SerproProxyUsageItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
        private readonly TenantIntegraHealthService $integraHealth,
        private readonly TenantUsageQueryService $usage,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $tenantId, InboxCapabilities $role): Collection
    {
        return $this->serproAuthItems($tenantId)
            ->merge($this->proxyPowerItems($tenantId))
            ->merge($this->sourceAvailabilityItems($tenantId))
            ->merge($this->usageItems($tenantId))
            ->values();
    }

    /**
     * Autorização SERPRO do escritório: Termo, token, bloqueio (sem XML/token).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function serproAuthItems(int $tenantId): Collection
    {
        $items = collect();
        $env = SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        $auth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenantId)
            ->where('environment', $env->value)
            ->first();

        if ($auth === null) {
            $items->push($this->items->item(
                type: 'serpro_termo_missing',
                title: 'Integra Contador não configurado',
                body: 'Configure o Autor do Pedido e envie o Termo de Autorização. Credenciais globais SERPRO não são expostas ao tenant.',
                reasons: ['serpro_auth_missing', 'next:CONFIGURE_AUTHOR'],
                clientId: null,
                establishmentId: null,
                occurredAt: now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));

            return $items->values();
        }

        $actions = $auth->computeActionsRequired();
        $actionCodes = array_column($actions, 'code');

        if (in_array('UPLOAD_TERMO', $actionCodes, true)) {
            $items->push($this->items->item(
                type: 'serpro_termo_missing',
                title: 'Termo de Autorização ausente',
                body: 'Envie o Termo assinado externamente. O XML e tokens não são recuperáveis pela API.',
                reasons: ['UPLOAD_TERMO'],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        if (in_array('TERMO_EXPIRED', $actionCodes, true)) {
            $items->push($this->items->item(
                type: 'serpro_termo_expired',
                title: 'Termo de Autorização expirado',
                body: 'Envie um novo Termo assinado. Consultas e mutações permanecem bloqueadas até regularização.',
                reasons: ['TERMO_EXPIRED'],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->termo_valid_to?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        if (in_array('REFRESH_PROCURADOR_TOKEN', $actionCodes, true)) {
            $items->push($this->items->item(
                type: 'serpro_token_expiring',
                title: 'Token do procurador ausente ou expirado',
                body: 'Renove o token do procurador (reapresentação do Termo conforme política). Sem material de token na resposta.',
                reasons: ['REFRESH_PROCURADOR_TOKEN'],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->procurador_token_expires_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        } elseif (
            $auth->procurador_token_expires_at !== null
            && $auth->procurador_token_expires_at->isFuture()
            && $auth->procurador_token_expires_at->lessThan(now()->addHours(24))
        ) {
            $items->push($this->items->item(
                type: 'serpro_token_expiring',
                title: 'Token do procurador expira em breve',
                body: 'Planeje a renovação nas próximas 24 horas.',
                reasons: ['TOKEN_EXPIRING_24H'],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->procurador_token_expires_at->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        if ($auth->status === SerproAuthorizationStatus::ActionRequired
            || in_array('SIGNATURE_REQUIRED', $actionCodes, true)
            || in_array('A3_INTERACTIVE', $actionCodes, true)
        ) {
            $items->push($this->items->item(
                type: 'serpro_auth_action_required',
                title: 'Ação necessária na autorização SERPRO',
                body: $this->items->sanitizeText($auth->action_required_reason)
                    ?? 'Assinatura interativa ou revalidação do Termo necessária.',
                reasons: ['ACTION_REQUIRED'],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        if (in_array($auth->status, [
            SerproAuthorizationStatus::Blocked,
            SerproAuthorizationStatus::Revoked,
            SerproAuthorizationStatus::Expired,
        ], true)) {
            $items->push($this->items->item(
                type: 'serpro_auth_blocked',
                title: 'Autorização SERPRO bloqueada ('.$auth->status->value.')',
                body: 'Chamadas ao Integra Contador estão impedidas para este escritório até regularização.',
                reasons: ['auth:'.$auth->status->value],
                clientId: null,
                establishmentId: null,
                occurredAt: $auth->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        // Deep-links de settings
        return $items->map(function (array $item) {
            $item['links'] = array_merge($item['links'] ?? [], [
                'serpro_authorization' => '/conta/escritorio',
            ]);

            return $item;
        })->values();
    }

    /**
     * Procurações expiradas / ausentes para clientes ativos (sem conteúdo do instrumento).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function proxyPowerItems(int $tenantId): Collection
    {
        $items = collect();

        $expired = TaxProxyPower::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('status', TaxProxyPowerStatus::Expired)
                    ->orWhere(function ($q2) {
                        $q2->where('status', TaxProxyPowerStatus::Active)
                            ->whereNotNull('valid_to')
                            ->where('valid_to', '<=', now());
                    });
            })
            ->with('client')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($expired as $power) {
            $client = $power->client;
            $item = $this->items->item(
                type: 'proxy_power_expired',
                title: 'Procuração expirada: '.($client ? $this->items->clientLabel($client) : 'cliente'),
                body: 'Poder '.$power->power_code.' (serviço '.($power->service_code ?? '—').') exige renovação. Conteúdo da procuração e tokens não são expostos.',
                reasons: ['proxy_expired', 'power:'.$power->power_code],
                clientId: $power->client_id,
                establishmentId: null,
                occurredAt: $power->valid_to?->toIso8601String() ?? $power->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'proxy:exp:'.$power->id), 0, 32);
            $item['links'] = array_merge($item['links'] ?? [], [
                'client' => '/clients/'.$power->client_id.'/dados-adicionais',
            ]);
            $items->push($item);
        }

        // Clientes ativos sem nenhuma procuração ACTIVE
        $clientIdsWithActive = TaxProxyPower::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TaxProxyPowerStatus::Active)
            ->where(function ($q) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->pluck('client_id')
            ->unique()
            ->all();

        $clientsMissing = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($clientIdsWithActive !== [], fn ($q) => $q->whereNotIn('id', $clientIdsWithActive))
            ->orderBy('id')
            ->limit(25)
            ->get();

        // Só alertar ausência se já existe onboarding SERPRO (evita ruído em escritórios só ADN)
        $hasAuth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('termo_vault_object_id')
            ->exists();

        if ($hasAuth) {
            foreach ($clientsMissing as $client) {
                $item = $this->items->item(
                    type: 'proxy_power_missing',
                    title: 'Procuração ausente: '.$this->items->clientLabel($client),
                    body: 'Nenhum poder ACTIVE vinculado. Importe ou sincronize procurações antes de consultas Integra.',
                    reasons: ['proxy_missing'],
                    clientId: $client->id,
                    establishmentId: null,
                    occurredAt: now()->toIso8601String(),
                    role: null,
                    establishment: null,
                    cursor: null,
                );
                $item['id'] = substr(hash('sha256', 'proxy:miss:'.$client->id), 0, 32);
                $item['links'] = array_merge($item['links'] ?? [], [
                    'client' => '/clients/'.$client->id.'/dados-adicionais',
                ]);
                $items->push($item);
            }
        }

        return $items->values();
    }

    /**
     * Fonte indisponível / consulta bloqueada (saúde platform sanitizada + runs blocked).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceAvailabilityItems(int $tenantId): Collection
    {
        $items = collect();
        $env = SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        try {
            $health = $this->integraHealth->forEnvironment($env);
        } catch (\Throwable) {
            $health = ['available' => true, 'kill_switch' => false, 'circuit_open' => false];
        }

        if (! ($health['available'] ?? true) || ($health['kill_switch'] ?? false) || ($health['circuit_open'] ?? false)) {
            $reasons = [];
            if ($health['kill_switch'] ?? false) {
                $reasons[] = 'kill_switch';
            }
            if ($health['circuit_open'] ?? false) {
                $reasons[] = 'circuit_open';
            }
            if (! ($health['available'] ?? true)) {
                $reasons[] = 'platform_unavailable';
            }
            $item = $this->items->item(
                type: 'source_unavailable',
                title: 'Integra Contador temporariamente indisponível',
                body: 'Indisponibilidade geral sanitizada. Sem métricas ou identidade de outros escritórios. Tente novamente após normalização.',
                reasons: $reasons,
                clientId: null,
                establishmentId: null,
                occurredAt: now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'src:unavail:'.$tenantId.':'.implode(',', $reasons)), 0, 32);
            $items->push($item);
        }

        $blockedRuns = FiscalMonitoringRun::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'BLOCKED')
            ->where('created_at', '>=', now()->subDay())
            ->with('client')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($blockedRuns as $run) {
            $client = $run->client;
            $item = $this->items->item(
                type: 'query_blocked',
                title: 'Consulta bloqueada: '.($client ? $this->items->clientLabel($client) : 'cliente'),
                body: $this->items->sanitizeText($run->error_message)
                    ?? ('Motivo: '.($run->error_code ?? $run->skip_reason ?? 'BLOCKED').'. Serviço '.$run->service_code.'.'),
                reasons: array_values(array_filter([
                    'query_blocked',
                    $run->error_code,
                    $run->skip_reason,
                    'svc:'.$run->service_code,
                ])),
                clientId: $run->client_id,
                establishmentId: null,
                occurredAt: $run->finished_at?->toIso8601String()
                    ?? $run->created_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'qblock:'.$run->id), 0, 32);
            $item['links'] = array_merge($item['links'] ?? [], [
                'monitoring' => '/monitoring',
            ]);
            // Sem retry imediato se for elegibilidade
            $item['actions'] = [
                ['type' => 'open', 'label' => 'Revisar bloqueio'],
            ];
            $items->push($item);
        }

        return $items->values();
    }

    /**
     * Consumo elevado / franquia.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function usageItems(int $tenantId): Collection
    {
        $items = collect();

        try {
            $raw = $this->usage->summary($tenantId);
            $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];
        } catch (\Throwable) {
            return $items;
        }

        $ratio = $summary['franchise_ratio'] ?? null;
        $alert = (bool) ($summary['alert_threshold_reached'] ?? false);
        $remaining = $summary['remaining'] ?? null;
        $quota = $summary['franchise_quota'] ?? null;

        if ($quota !== null && $remaining !== null && $remaining <= 0) {
            $item = $this->items->item(
                type: 'usage_franchise_exceeded',
                title: 'Franquia SERPRO esgotada no período',
                body: 'Uso '.($summary['used_quantity'] ?? 0).' de '.$quota
                    .'. Chamadas não essenciais podem ser bloqueadas conforme plano. Detalhe em consumo do escritório.',
                reasons: ['FRANCHISE_EXCEEDED'],
                clientId: null,
                establishmentId: null,
                occurredAt: now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'usage:ex:'.$tenantId.':'.($summary['period_year'] ?? '').($summary['period_month'] ?? '')), 0, 32);
            $item['links'] = ['usage' => '/conta/consumo'];
            $items->push($item);
        } elseif ($alert || ($ratio !== null && $ratio >= 0.8)) {
            $pct = $ratio !== null ? (int) round($ratio * 100) : null;
            $item = $this->items->item(
                type: 'usage_high',
                title: 'Consumo SERPRO próximo do limite',
                body: ($pct !== null ? "Uso em {$pct}% da franquia. " : '')
                    .'Revise o detalhamento do próprio tenant. Sem fatura global ou custo de outros escritórios.',
                reasons: ['USAGE_ALERT', 'threshold'],
                clientId: null,
                establishmentId: null,
                occurredAt: now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'usage:hi:'.$tenantId.':'.($summary['period_year'] ?? '').($summary['period_month'] ?? '')), 0, 32);
            $item['links'] = ['usage' => '/conta/consumo'];
            $items->push($item);
        }

        return $items->values();
    }
}
