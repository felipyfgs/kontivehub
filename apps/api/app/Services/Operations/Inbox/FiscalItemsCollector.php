<?php

namespace App\Services\Operations\Inbox;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalMutationStatus;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunResult;
use App\Enums\TaxGuidePaymentStatus;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMutationOperation;
use App\Models\FiscalPendingItem;
use App\Models\TaxGuide;
use Illuminate\Support\Collection;

/**
 * Pendências fiscais, guias, mutações incertas, parsing e runs SITFIS.
 */
final class FiscalItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $role): Collection
    {
        // collect() base: map() em Eloquent\Collection devolve Eloquent\Collection
        // e merge() tentaria getKey() nos arrays de item.
        return collect()
            ->merge($this->fiscalPendingItems($officeId))
            ->merge($this->guideDueItems($officeId))
            ->merge($this->uncertainMutationItems($officeId))
            ->merge($this->parsingAlertItems($officeId))
            ->merge($this->sitfisRunItems($officeId))
            ->values();
    }

    /**
     * Pendências fiscais abertas de severidade alta/crítica.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fiscalPendingItems(int $officeId): Collection
    {
        $rows = FiscalPendingItem::query()
            ->where('office_id', $officeId)
            ->where('status', FiscalPendingStatus::Open)
            ->whereIn('severity', [
                FiscalFindingSeverity::Critical->value,
                FiscalFindingSeverity::High->value,
                FiscalFindingSeverity::Medium->value,
            ])
            ->with('client')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        return $rows->map(function (FiscalPendingItem $pending) {
            $sev = match ($pending->severity) {
                FiscalFindingSeverity::Critical => 'critical',
                FiscalFindingSeverity::High => 'high',
                default => 'medium',
            };
            $client = $pending->client;
            $item = $this->items->item(
                type: 'fiscal_pending',
                title: $this->items->sanitizeText($pending->title) ?? 'Pendência fiscal',
                body: $this->items->sanitizeText($pending->detail)
                    ?? ('Código '.($pending->code ?? '—').'. Abrir detalhe do cliente.'),
                reasons: array_values(array_filter([
                    'fiscal_pending',
                    $pending->code,
                    $pending->situation?->value,
                ])),
                clientId: $pending->client_id,
                establishmentId: null,
                occurredAt: $pending->due_at?->toIso8601String()
                    ?? $pending->created_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['severity'] = $sev;
            $item['id'] = substr(hash('sha256', 'fpend:'.$pending->id), 0, 32);
            $item['links'] = array_merge($item['links'] ?? [], [
                'pending' => '/fiscal/pendencias/'.$pending->id,
                'client' => $pending->client_id ? '/clients/'.$pending->client_id : null,
            ]);
            $item['links'] = array_filter($item['links']);

            return $item;
        })->values();
    }

    /**
     * Guias com vencimento próximo (sem PDF/bytes).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function guideDueItems(int $officeId): Collection
    {
        $guides = TaxGuide::query()
            ->where('office_id', $officeId)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(7))
            ->where('due_at', '>=', now()->subDays(3))
            ->whereNotIn('payment_status', [TaxGuidePaymentStatus::Confirmed->value])
            ->with('client')
            ->orderBy('due_at')
            ->limit(30)
            ->get();

        return $guides->map(function (TaxGuide $guide) {
            $client = $guide->client;
            $item = $this->items->item(
                type: 'guide_due_soon',
                title: 'Guia a vencer: '.($client ? $this->items->clientLabel($client) : 'cliente'),
                body: 'Serviço '.($guide->service_code ?? '—').' · vencimento '
                    .($guide->due_at?->toDateString() ?? '—')
                    .'. Pagamento: '.($guide->payment_status?->value ?? 'UNKNOWN').'. Sem artefato na inbox.',
                reasons: ['guide_due', 'svc:'.($guide->service_code ?? 'na')],
                clientId: $guide->client_id,
                establishmentId: $guide->establishment_id,
                occurredAt: $guide->due_at?->toIso8601String() ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'guide:due:'.$guide->id), 0, 32);
            $item['links'] = array_merge($item['links'] ?? [], [
                'guide' => '/fiscal/guias/'.$guide->id,
            ]);

            return $item;
        })->values();
    }

    /**
     * Mutações / guias com resultado incerto — crítico, sem retry imediato.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function uncertainMutationItems(int $officeId): Collection
    {
        $ops = FiscalMutationOperation::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [
                FiscalMutationStatus::UnknownResult->value,
                FiscalMutationStatus::Reconciling->value,
                FiscalMutationStatus::Sent->value,
            ])
            ->with('client')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $ops->map(function (FiscalMutationOperation $op) {
            $client = $op->client;
            $item = $this->items->item(
                type: 'mutation_unknown_result',
                title: 'Resultado incerto: '.($op->operation_code ?? 'mutação')
                    .' ('.($client ? $this->items->clientLabel($client) : 'cliente').')',
                body: 'Estado '.$op->status->value.'. Reconciliação obrigatória antes de nova tentativa. Retry cego bloqueado.',
                reasons: ['UNKNOWN_RESULT', 'status:'.$op->status->value, 'no_blind_retry'],
                clientId: $op->client_id,
                establishmentId: null,
                occurredAt: $op->sent_at?->toIso8601String()
                    ?? $op->updated_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'mut:unc:'.$op->id), 0, 32);
            $item['links'] = array_merge($item['links'] ?? [], [
                'mutation' => '/fiscal/mutacoes/'.$op->id,
            ]);
            // Apenas reconciliação — nunca retry
            $item['actions'] = [
                ['type' => 'reconcile', 'label' => 'Reconciliar', 'mutation_id' => $op->id],
                ['type' => 'open', 'label' => 'Abrir'],
            ];

            return $item;
        })->values();
    }

    /**
     * Alertas de parsing / resultado de run com PARSE_ALERT.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function parsingAlertItems(int $officeId): Collection
    {
        $runs = FiscalMonitoringRun::query()
            ->where('office_id', $officeId)
            ->where(function ($q) {
                $q->where('error_code', 'like', '%PARSE%')
                    ->orWhere('result', FiscalRunResult::Partial->value ?? 'PARTIAL')
                    ->orWhere('skip_reason', 'like', '%PARSE%');
            })
            ->where('created_at', '>=', now()->subDays(3))
            ->with('client')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        // Filtrar só os que realmente indicam parsing (result Partial sozinho é ruidoso)
        $runs = $runs->filter(function (FiscalMonitoringRun $run) {
            $code = strtoupper((string) ($run->error_code ?? ''));
            $skip = strtoupper((string) ($run->skip_reason ?? ''));

            return str_contains($code, 'PARSE')
                || str_contains($skip, 'PARSE')
                || str_contains($code, 'SCHEMA')
                || str_contains($code, 'XSD');
        });

        return $runs->map(function (FiscalMonitoringRun $run) {
            $client = $run->client;
            $item = $this->items->item(
                type: 'parsing_alert',
                title: 'Alerta de parsing: '.($client ? $this->items->clientLabel($client) : 'run #'.$run->id),
                body: $this->items->sanitizeText($run->error_message)
                    ?? 'Resposta oficial com schema/parsing incompleto. Evidência preservada quando bem-formada.',
                reasons: array_values(array_filter(['parsing', $run->error_code, $run->skip_reason])),
                clientId: $run->client_id,
                establishmentId: null,
                occurredAt: $run->finished_at?->toIso8601String()
                    ?? $run->created_at?->toIso8601String()
                    ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'parse:'.$run->id), 0, 32);

            return $item;
        })->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sitfisRunItems(int $officeId): Collection
    {
        // Só falhas e conclusões com alerta de parse — COMPLETED limpos não poluem a inbox.
        return FiscalMonitoringRun::query()
            ->where('office_id', $officeId)
            ->where('service_code', 'SITFIS')
            ->where(function ($q): void {
                $q->where('status', 'FAILED')
                    ->orWhere(function ($q2): void {
                        $q2->where('status', 'COMPLETED')
                            ->where('verification_state', 'PARSE_ALERT');
                    })
                    ->orWhere('status', 'BLOCKED');
            })
            ->where('created_at', '>=', now()->subDays(3))
            ->with('client')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (FiscalMonitoringRun $run): array {
                $status = $run->status?->value ?? '';
                $failed = $status === 'FAILED';
                $blocked = $status === 'BLOCKED';
                $parseAlert = $run->verification_state?->value === 'PARSE_ALERT';
                $type = match (true) {
                    $failed => 'sitfis_run_failed',
                    $blocked => 'sitfis_run_failed',
                    $parseAlert => 'sitfis_run_completed',
                    default => 'sitfis_run_failed',
                };
                $title = match (true) {
                    $failed => 'Atualização SITFIS requer atenção',
                    $blocked => 'Atualização SITFIS bloqueada',
                    $parseAlert => 'SITFIS concluída com alerta de layout',
                    default => 'Atualização SITFIS requer atenção',
                };
                $body = match (true) {
                    $failed => 'A consulta terminou com erro operacional. Abra o detalhe para revisar a próxima ação.',
                    $blocked => 'A consulta foi bloqueada por gate operacional (autorização, capacidade ou orçamento).',
                    $parseAlert => 'Relatório capturado, mas o layout não foi reconhecido. Revise o artefato e o parser.',
                    default => 'A atualização SITFIS requer atenção.',
                };
                $item = $this->items->item(
                    type: $type,
                    title: $title,
                    body: $body,
                    reasons: array_values(array_filter([
                        $failed ? 'failed' : ($blocked ? 'blocked' : ($parseAlert ? 'parse_alert' : 'attention')),
                        $run->error_code,
                        'source:'.($run->source_provenance?->value ?? 'UNVERIFIED'),
                    ])),
                    clientId: $run->client_id,
                    establishmentId: null,
                    occurredAt: $run->finished_at?->toIso8601String()
                        ?? $run->updated_at?->toIso8601String()
                        ?? now()->toIso8601String(),
                    role: null,
                    establishment: null,
                    cursor: null,
                );
                $item['id'] = substr(hash('sha256', 'sitfis-run:'.$run->id), 0, 32);
                $item['links'] = ['run' => '/fiscal/runs/'.$run->id];

                return $item;
            })
            ->values();
    }
}
