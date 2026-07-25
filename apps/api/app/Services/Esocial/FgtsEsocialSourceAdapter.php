<?php

namespace App\Services\Esocial;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Exceptions\EsocialBxException;
use App\Models\Establishment;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Adapter do núcleo fiscal para ESOCIAL/FGTS (cobertura parcial).
 * Não chama portal FGTS Digital; usa apenas EsocialEventClient.
 */
final class FgtsEsocialSourceAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly FgtsEsocialMonitoringService $monitoring,
        private readonly EsocialBxReadinessService $readiness,
    ) {}

    public function systemCode(): string
    {
        return (string) config('fgts_esocial.system_code', 'ESOCIAL');
    }

    public function serviceCode(): string
    {
        return (string) config('fgts_esocial.service_code', 'FGTS');
    }

    public function operationCode(): string
    {
        return (string) config('fgts_esocial.operation_code', 'MONITOR');
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function moduleKey(): ?string
    {
        return (string) config('fgts_esocial.module_key', 'fgts');
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->systemCode()) === 0
            && strcasecmp($request->serviceCode, $this->serviceCode()) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $readiness = $this->readiness->check($request->office, $request->client);
        if (! $readiness->ready) {
            $blocker = $readiness->blockers[0];

            return FiscalAdapterResult::blocked($blocker['message'], $blocker['code']);
        }

        $competence = $this->resolveCompetenceKey($request);
        if ($competence === null) {
            return FiscalAdapterResult::skipped(
                'Competência YYYY-MM obrigatória para monitoramento FGTS/eSocial (informe competence ou context.competence_period_key).',
                'COMPETENCE_REQUIRED',
            );
        }

        $establishment = null;
        $estId = $request->context['establishment_id'] ?? null;
        if (is_numeric($estId)) {
            $establishment = Establishment::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->office->id)
                ->where('client_id', $request->client->id)
                ->whereKey((int) $estId)
                ->first();
        }

        try {
            $out = $this->monitoring->syncCompetence(
                office: $request->office,
                client: $request->client,
                competencePeriodKey: $competence,
                establishment: $establishment,
                run: $request->run,
                now: CarbonImmutable::now(),
            );
        } catch (EsocialBxException $exception) {
            if ($exception->blocked) {
                return FiscalAdapterResult::blocked(
                    'Consulta oficial eSocial BX bloqueada por regra operacional.',
                    $exception->stableCode,
                );
            }

            if ($exception->retryable) {
                return new FiscalAdapterResult(
                    result: FiscalRunResult::Requeued,
                    situation: FiscalSituation::Unknown,
                    coverage: FiscalCoverage::Partial,
                    shouldRequeue: true,
                    errorCode: $exception->stableCode,
                    errorMessage: 'Falha temporária sanitizada na consulta eSocial BX.',
                    requeueAfterSeconds: 120,
                );
            }

            return FiscalAdapterResult::failed(
                'Falha permanente sanitizada na consulta eSocial BX.',
                $exception->stableCode,
                FiscalCoverage::Partial,
            );
        } catch (Throwable $e) {
            return FiscalAdapterResult::failed(
                'Falha interna sanitizada ao sincronizar eventos eSocial.',
                'ESOCIAL_SYNC_FAILED',
                FiscalCoverage::Partial,
            );
        }

        $projection = $out['projection'];
        $status = $out['status'];

        // Evidência agregada da projeção (estados + limitações + códigos de evento).
        $evidencePayload = [
            'module' => 'fgts',
            'competence_period_key' => $competence,
            'coverage' => $projection->coverage->value,
            'situation' => $projection->situation->value,
            'closure_status' => $projection->closureStatus->value,
            'totalization_status' => $projection->totalizationStatus->value,
            'guide_status' => $projection->guideStatus->value,
            'payment_status' => $projection->paymentStatus->value,
            'limitations' => $projection->limitations,
            'declares_fgts_digital_debt' => false,
            'events_count' => $out['events_count'],
            'evidences' => array_map(
                static fn ($e) => [
                    'event_code' => $e->event_code?->value,
                    'content_sha256' => $e->content_sha256,
                    'receipt_number' => $e->receipt_number,
                ],
                $out['evidences'],
            ),
            'status_id' => $status->id,
            'normalized' => $projection->normalized,
        ];

        $bytes = json_encode($evidencePayload, JSON_THROW_ON_ERROR);

        // Nunca UP_TO_DATE: cobertura parcial + guia/pagamento unsupported.
        $situation = $projection->situation;
        if ($situation === FiscalSituation::UpToDate) {
            $situation = FiscalSituation::Attention;
        }

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: FiscalCoverage::Partial,
            evidenceBytes: $bytes,
            evidenceContentType: 'application/json',
            sourceVersion: (string) config('fgts_esocial.evidence.source_version', 'unverified-1'),
            normalized: $projection->normalized,
            findings: $projection->findings,
            itemsProcessed: max(1, count($out['evidences'])),
        );
    }

    private function resolveCompetenceKey(FiscalAdapterRequest $request): ?string
    {
        if ($request->competence !== null && preg_match('/^\d{4}-\d{2}$/', (string) $request->competence->period_key)) {
            return (string) $request->competence->period_key;
        }

        $fromContext = $request->context['competence_period_key']
            ?? $request->progress['competence_period_key']
            ?? null;
        if (is_string($fromContext) && preg_match('/^\d{4}-\d{2}$/', $fromContext)) {
            return $fromContext;
        }

        // Default: competência civil anterior (monitoramento operacional).
        return CarbonImmutable::now()->subMonth()->format('Y-m');
    }
}
