<?php

namespace App\Services\Outbound;

use App\Enums\OutboundCaptureRunStatus;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundSeriesStatus;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundCapacitySnapshot;
use App\Models\OutboundCaptureRun;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Métricas de baixa cardinalidade — sem chave completa, CSC, PFX ou XML.
 */
final class OutboundMetrics
{
    /**
     * Contador de baixa cardinalidade (sem CNPJ/chave como label).
     *
     * @param  array<string, scalar|null>  $labels
     */
    public function increment(string $name, int $by = 1, array $labels = []): void
    {
        // Sem backend Prometheus nesta fase — log estruturado + cache simples.
        $safeLabels = array_intersect_key($labels, array_flip([
            'channel', 'environment', 'outcome', 'model',
            'urgency_band', 'source', 'competence', 'risk',
            'cause', 'state', 'decision', 'exchange_kind',
        ]));
        // Nunca aceitar labels de alta cardinalidade / PII
        unset($safeLabels['access_key'], $safeLabels['cnpj'], $safeLabels['pfx'], $safeLabels['password']);

        Log::info('metrics.counter', [
            'name' => $name,
            'by' => $by,
            'labels' => $safeLabels,
        ]);

        $key = 'metrics.counter.'.$name;
        try {
            Cache::increment($key, $by);
        } catch (\Throwable) {
            // cache array driver may not support increment in all versions
            $cur = (int) Cache::get($key, 0);
            Cache::put($key, $cur + $by, 86400);
        }
    }

    /**
     * Snapshot de fechamento mensal / capacidade — labels de baixa cardinalidade.
     *
     * @return array<string, mixed>
     */
    public function deadlineSnapshot(?int $officeId = null, ?string $competence = null): array
    {
        $q = MaOutboundRetrievalRequest::query()
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey);
        if ($officeId !== null) {
            $q->where('office_id', $officeId);
        }
        if ($competence !== null && $competence !== '') {
            $q->where('competence', $competence);
        }

        $byBand = [];
        foreach (OutboundUrgencyBand::cases() as $band) {
            $byBand[$band->value] = (clone $q)->where('urgency_band', $band->value)->count();
        }

        $known = (clone $q)->count();
        $captured = (clone $q)->whereIn('recovery_status', [
            SvrsNfceRecoveryStatus::Captured->value,
            SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
        ])->count();
        $overdue = $byBand[OutboundUrgencyBand::Overdue->value] ?? 0;
        $contingency = $byBand[OutboundUrgencyBand::Contingency->value] ?? 0;
        $slotsDue = (clone $q)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value])
            ->count();

        $snapQ = OutboundCapacitySnapshot::query()->orderByDesc('id');
        if ($officeId !== null) {
            $snapQ->where('office_id', $officeId);
        }
        if ($competence !== null && $competence !== '') {
            $snapQ->where('competence', $competence);
        }
        $latest = $snapQ->first();

        $bySource = (clone $q)
            ->whereNotNull('capture_source')
            ->selectRaw('capture_source, count(*) as c')
            ->groupBy('capture_source')
            ->pluck('c', 'capture_source')
            ->all();

        $data = [
            'known_total' => $known,
            'captured_total' => $captured,
            'pending_total' => max(0, $known - $captured),
            'by_band' => $byBand,
            'overdue' => $overdue,
            'contingency' => $contingency,
            'slots_due' => $slotsDue,
            'by_capture_source' => $bySource,
            'capacity' => $latest === null ? null : [
                'demand_exchanges' => $latest->demand_exchanges,
                'safe_capacity_exchanges' => $latest->safe_capacity_exchanges,
                'slack_exchanges' => $latest->slack_exchanges,
                'at_risk' => (bool) $latest->at_risk,
                'items_capacity_at_risk' => $latest->items_capacity_at_risk,
            ],
            'completeness_scope' => 'known_documents_only',
            'alerts' => $this->deadlineAlerts($overdue, $contingency, $latest),
        ];

        Log::info('metrics.outbound_deadline', [
            'office_scoped' => $officeId !== null,
            'competence' => $competence,
            // sem chave/CNPJ
            'known_total' => $known,
            'captured_total' => $captured,
            'overdue' => $overdue,
            'at_risk' => (bool) ($latest?->at_risk),
        ]);

        return $data;
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function deadlineAlerts(int $overdue, int $contingency, ?OutboundCapacitySnapshot $latest): array
    {
        $alerts = [];
        if ($latest !== null && $latest->at_risk) {
            $alerts[] = [
                'code' => 'CAPACITY_INSUFFICIENT',
                'severity' => 'high',
                'message' => 'Demanda projetada excede capacidade segura (60%) até a meta interna.',
            ];
        }
        if ($overdue > 0) {
            $alerts[] = [
                'code' => 'ITEMS_OVERDUE',
                'severity' => 'critical',
                'message' => "Há {$overdue} documento(s) vencido(s) sem XML canônico (conhecidos).",
            ];
        }
        if ($contingency > 0) {
            $alerts[] = [
                'code' => 'CONTINGENCY_OPEN',
                'severity' => 'medium',
                'message' => "Há {$contingency} item(ns) em contingência — priorize importação/pacote.",
            ];
        }

        return $alerts;
    }

    /**
     * @return array{
     *   queue: string,
     *   series_idle: int,
     *   series_blocked: int,
     *   series_incident: int,
     *   gaps_exhausted: int,
     *   xml_pending: int,
     *   runs_last_24h: int,
     *   runs_failed_24h: int,
     *   labels: array<string, string>
     * }
     */
    public function snapshot(?int $officeId = null): array
    {
        $seriesQ = OutboundSeriesCursor::query();
        $numQ = OutboundNumberState::query();
        $runQ = OutboundCaptureRun::query()->where('created_at', '>=', now()->subDay());

        if ($officeId !== null) {
            $seriesQ->where('office_id', $officeId);
            $numQ->where('office_id', $officeId);
            $runQ->where('office_id', $officeId);
        }

        $data = [
            'queue' => (string) config('sefaz.ma_outbound.queue', 'capture-outbound-ma'),
            'series_idle' => (clone $seriesQ)->where('status', OutboundSeriesStatus::Idle)->count(),
            'series_blocked' => (clone $seriesQ)->where('status', OutboundSeriesStatus::Blocked)->count(),
            'series_incident' => (clone $seriesQ)->where('status', OutboundSeriesStatus::FiscalIncident)->count(),
            'gaps_exhausted' => (clone $numQ)->where('status', OutboundNumberStatus::ExhaustedVisible)->count(),
            'xml_pending' => (clone $numQ)->where('status', OutboundNumberStatus::XmlPending)->count(),
            'runs_last_24h' => (clone $runQ)->count(),
            'runs_failed_24h' => (clone $runQ)->where('status', OutboundCaptureRunStatus::Failed)->count(),
            'labels' => [
                'channel' => 'MA_OUTBOUND',
                'position_kind' => 'nNF',
                'uf' => 'MA',
            ],
        ];

        Log::info('metrics.outbound_ma', $data);

        return $data;
    }
}
