<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\FiscalSituation;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxPeriodGranularity;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\FiscalCompetence;
use App\Models\Office;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\TaxObligationRegimeRule;
use App\Models\TaxObligationVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Projeção de obrigação por contribuinte/competência com aplicabilidade honesta (11.2).
 */
final class TaxObligationProjectionService
{
    public function __construct(
        private readonly TaxObligationCatalogService $catalog,
        private readonly TaxDeadlineCalendarService $deadlines,
    ) {}

    /**
     * Resolve aplicabilidade sem persistir.
     *
     * @return array{
     *   applicability: TaxObligationApplicability,
     *   basis: string,
     *   regime: TaxRegimeCode,
     *   version: TaxObligationVersion|null,
     *   rule: TaxObligationRegimeRule|null
     * }
     */
    public function resolveApplicability(
        Client $client,
        TaxObligationDefinition $obligation,
        ?TaxObligationVersion $version = null,
    ): array {
        $version ??= $this->catalog->currentVersion($obligation);
        $regime = TaxRegimeCode::normalize($client->tax_regime);

        if ($version === null) {
            return [
                'applicability' => TaxObligationApplicability::Unknown,
                'basis' => 'Sem versão de regra de obrigação disponível.',
                'regime' => $regime,
                'version' => null,
                'rule' => null,
            ];
        }

        $rule = TaxObligationRegimeRule::query()
            ->where('obligation_version_id', $version->id)
            ->where('tax_regime', $regime->value)
            ->orderBy('priority')
            ->first();

        if ($rule !== null) {
            return [
                'applicability' => $rule->applicability ?? TaxObligationApplicability::Unknown,
                'basis' => (string) ($rule->rule_basis
                    ?? $version->rule_basis
                    ?? 'Regra de regime versionada.'),
                'regime' => $regime,
                'version' => $version,
                'rule' => $rule,
            ];
        }

        $default = $version->default_applicability ?? TaxObligationApplicability::Unknown;

        return [
            'applicability' => $default,
            'basis' => (string) ($version->rule_basis
                ?? 'Aplicabilidade padrão da versão (sem regra específica de regime).'),
            'regime' => $regime,
            'version' => $version,
            'rule' => null,
        ];
    }

    /**
     * Cria/atualiza projeção para contribuinte + obrigação + competência.
     * NÃO cria pendência presumida quando applicability = UNKNOWN.
     */
    public function project(
        Office $office,
        Client $client,
        TaxObligationDefinition $obligation,
        string $periodKey,
        ?int $periodYear = null,
        ?int $periodMonth = null,
        ?int $competenceId = null,
        bool $computeDue = true,
    ): TaxObligationProjection {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
        if (! $obligation->is_active) {
            throw new RuntimeException('Obrigação inativa no catálogo.');
        }

        [$year, $month] = $this->parsePeriod($periodKey, $periodYear, $periodMonth, $obligation);

        return DB::transaction(function () use (
            $office,
            $client,
            $obligation,
            $periodKey,
            $year,
            $month,
            $competenceId,
            $computeDue,
        ) {
            $resolved = $this->resolveApplicability($client, $obligation);
            $applicability = $resolved['applicability'];
            $version = $resolved['version'];

            $situation = $this->situationFromApplicability($applicability);
            $deliveryStatus = $this->initialDeliveryStatus($applicability);

            $dueAt = null;
            $calendarVersionId = null;
            $dueSnapshot = null;

            // Prazos só fazem sentido para obrigações aplicáveis (ou ainda desconhecidas em aberto).
            if ($computeDue && $applicability !== TaxObligationApplicability::NotApplicable
                && $applicability !== TaxObligationApplicability::Unsupported
            ) {
                $calc = $this->deadlines->calculateDue($obligation, $periodKey, $year, $month);
                $dueAt = $calc['due_at'];
                $calendarVersionId = $calc['calendar_version_id'];
                $dueSnapshot = $calc['snapshot'];
            }

            $existing = TaxObligationProjection::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('obligation_definition_id', $obligation->id)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            // Não sobrescrever entrega conclusiva nem fechar indevidamente.
            $hasConclusive = $existing?->conclusive_evidence_id !== null;
            if ($hasConclusive) {
                $situation = FiscalSituation::UpToDate;
                $deliveryStatus = FiscalSituation::UpToDate;
            }

            $isOpen = ! $hasConclusive
                && $applicability !== TaxObligationApplicability::NotApplicable
                && $applicability !== TaxObligationApplicability::Unsupported;

            $closedAt = null;
            if ($applicability === TaxObligationApplicability::NotApplicable
                || $applicability === TaxObligationApplicability::Unsupported
            ) {
                $closedAt = $existing?->closed_at ?? CarbonImmutable::now();
                $isOpen = false;
            }
            if ($hasConclusive) {
                $closedAt = $existing?->closed_at ?? CarbonImmutable::now();
                $isOpen = false;
            }

            $payload = [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'obligation_definition_id' => $obligation->id,
                'obligation_version_id' => $version?->id,
                'calendar_version_id' => $calendarVersionId ?? $existing?->calendar_version_id,
                'competence_id' => $competenceId ?? $existing?->competence_id,
                'period_key' => $periodKey,
                'period_year' => $year,
                'period_month' => $month,
                'applicability' => $applicability,
                'situation' => $situation,
                'delivery_status' => $deliveryStatus,
                'due_at' => $hasConclusive ? $existing?->due_at : ($dueAt ?? $existing?->due_at),
                'due_rule_snapshot' => $hasConclusive
                    ? $existing?->due_rule_snapshot
                    : ($dueSnapshot ?? $existing?->due_rule_snapshot),
                'applicability_basis' => $resolved['basis']
                    .' [regime='.$resolved['regime']->value
                    .'; rule_version='.($version?->version ?? 'n/a')
                    .'; rule_key='.($version?->rule_key ?? 'n/a').']',
                'is_open' => $isOpen,
                'closed_at' => $closedAt,
                'metadata' => array_merge($existing?->metadata ?? [], [
                    'tax_regime' => $resolved['regime']->value,
                    'regime_rule_id' => $resolved['rule']?->id,
                    'projected_at' => CarbonImmutable::now()->toIso8601String(),
                ]),
            ];

            if ($existing !== null) {
                // Preserva evidência e histórico de prazo.
                $payload['conclusive_evidence_id'] = $existing->conclusive_evidence_id;
                $payload['evidence_artifact_id'] = $existing->evidence_artifact_id;
                $payload['due_history'] = $existing->due_history;
                $existing->forceFill($payload)->save();

                return $existing->fresh(['obligation', 'obligationVersion', 'calendarVersion']);
            }

            $created = TaxObligationProjection::query()->create($payload);

            return $created->fresh(['obligation', 'obligationVersion', 'calendarVersion']);
        });
    }

    /**
     * Projeta todas as obrigações ativas do catálogo para o contribuinte/competência.
     *
     * @return list<TaxObligationProjection>
     */
    public function projectAllForClient(
        Office $office,
        Client $client,
        string $periodKey,
        ?int $periodYear = null,
        ?int $periodMonth = null,
    ): array {
        $out = [];
        foreach ($this->catalog->listDefinitions(true) as $definition) {
            $out[] = $this->project(
                $office,
                $client,
                $definition,
                $periodKey,
                $periodYear,
                $periodMonth,
            );
        }

        return $out;
    }

    /**
     * Vincula FiscalCompetence quando existir (idempotente).
     */
    public function attachCompetence(TaxObligationProjection $projection, FiscalCompetence $competence): TaxObligationProjection
    {
        if ((int) $projection->office_id !== (int) $competence->office_id) {
            throw new RuntimeException('Competência de outro tenant.');
        }
        if ((int) $projection->client_id !== (int) $competence->client_id) {
            throw new RuntimeException('Competência de outro contribuinte.');
        }

        $projection->forceFill(['competence_id' => $competence->id])->save();

        return $projection->fresh(['obligation', 'obligationVersion', 'calendarVersion']);
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    private function parsePeriod(
        string $periodKey,
        ?int $periodYear,
        ?int $periodMonth,
        TaxObligationDefinition $obligation,
    ): array {
        $periodKey = trim($periodKey);
        if ($periodKey === '') {
            throw new InvalidArgumentException('period_key obrigatório.');
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/^(\d{4})$/', $periodKey, $m)) {
            return [(int) $m[1], null];
        }
        if (preg_match('/^(\d{4})-Q([1-4])$/', $periodKey, $m)) {
            $quarter = (int) $m[2];
            $month = ($quarter - 1) * 3 + 1;

            return [(int) $m[1], $month];
        }

        if ($periodYear !== null) {
            return [$periodYear, $periodMonth];
        }

        // Fallback por granularidade
        if ($obligation->period_granularity === TaxPeriodGranularity::Annual
            && preg_match('/^\d{4}/', $periodKey)
        ) {
            return [(int) substr($periodKey, 0, 4), null];
        }

        throw new InvalidArgumentException("period_key inválido: {$periodKey}");
    }

    private function situationFromApplicability(TaxObligationApplicability $applicability): FiscalSituation
    {
        return match ($applicability) {
            TaxObligationApplicability::Applicable => FiscalSituation::Pending,
            TaxObligationApplicability::NotApplicable => FiscalSituation::NotApplicable,
            TaxObligationApplicability::Unknown => FiscalSituation::Unknown,
            TaxObligationApplicability::Unsupported => FiscalSituation::Unsupported,
        };
    }

    private function initialDeliveryStatus(TaxObligationApplicability $applicability): FiscalSituation
    {
        // APPLICABLE sem recibo → PENDING; UNKNOWN/UNSUPPORTED/N-A sem inventar entrega.
        return match ($applicability) {
            TaxObligationApplicability::Applicable => FiscalSituation::Pending,
            TaxObligationApplicability::NotApplicable => FiscalSituation::NotApplicable,
            TaxObligationApplicability::Unknown => FiscalSituation::Unknown,
            TaxObligationApplicability::Unsupported => FiscalSituation::Unsupported,
        };
    }
}
