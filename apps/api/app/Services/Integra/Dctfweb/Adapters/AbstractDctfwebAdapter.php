<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use InvalidArgumentException;

abstract class AbstractDctfwebAdapter implements FiscalSourceAdapter
{
    public function __construct(
        protected readonly DctfwebIntegraCaller $caller,
        protected readonly DctfwebCompetenceResolver $competences,
    ) {}

    abstract public function systemCode(): string;

    abstract public function serviceCode(): string;

    abstract public function operationCode(): string;

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return DctfwebCodes::MODULE;
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->systemCode()) === 0
            && strcasecmp($request->serviceCode, $this->serviceCode()) === 0
            && strcasecmp($request->operationCode, $this->operationCode()) === 0;
    }

    protected function resolvePeriodKey(FiscalAdapterRequest $request): string
    {
        if ($request->competence?->period_key) {
            return $this->competences->normalizePeriodKey($request->competence->period_key);
        }

        $fromProgress = $request->progress['period_key'] ?? $request->context['period_key'] ?? null;
        if (is_string($fromProgress) && $fromProgress !== '') {
            return $this->competences->normalizePeriodKey($fromProgress);
        }

        $event = $request->run->relationLoaded('lastUpdateEvent')
            ? $request->run->lastUpdateEvent
            : $request->run->lastUpdateEvent()->first();
        $meta = $event?->metadata;
        if (is_array($meta) && ! empty($meta['period_key'])) {
            return $this->competences->normalizePeriodKey((string) $meta['period_key']);
        }

        throw new InvalidArgumentException(
            'Competência (period_key) obrigatória para operação '.$this->operationCode()
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function callUpstream(FiscalAdapterRequest $request, array $payload = []): IntegraResponse
    {
        $response = $this->caller->call(
            request: $request,
            solutionCode: $this->systemCode(),
            serviceCode: $this->serviceCode(),
            operationCode: $this->operationCode(),
            payload: $payload,
        );

        return $response->hasSimulatedSource()
            ? $response->rejectSimulatedSource()
            : $response;
    }

    protected function failedFromResponse(IntegraResponse $response): FiscalAdapterResult
    {
        $code = $response->errorCode ?? 'UPSTREAM_ERROR';

        if (in_array($code, ['TIMEOUT', 'UNCERTAIN', 'UNCERTAIN_TIMEOUT'], true)
            || $response->httpStatus === 504) {
            return new FiscalAdapterResult(
                result: FiscalRunResult::Failed,
                situation: FiscalSituation::Error,
                coverage: $this->coverage(),
                errorCode: 'UNCERTAIN_TIMEOUT',
                errorMessage: $response->errorMessage ?? 'Timeout incerto na operação.',
            );
        }

        return FiscalAdapterResult::failed(
            $response->errorMessage ?? 'Falha na consulta Integra.',
            $code,
            $this->coverage(),
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool}>  $findings
     */
    protected function successResult(
        FiscalSituation $situation,
        string $evidenceBytes,
        array $normalized,
        array $findings = [],
        FiscalCoverage $coverage = FiscalCoverage::Full,
        ?string $sourceVersion = null,
        string $contentType = 'application/json',
    ): FiscalAdapterResult {
        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: $coverage,
            evidenceBytes: $evidenceBytes,
            evidenceContentType: $contentType,
            sourceVersion: $sourceVersion,
            normalized: $normalized,
            findings: $findings,
        );
    }

    /**
     * @return list<array{code:string,severity:string,title:string,detail?:string,situation?:string,creates_pending?:bool}>
     */
    protected function retificationFinding(bool $isRetification): array
    {
        if (! $isRetification) {
            return [];
        }

        return [[
            'code' => 'DCTFWEB_RETIFICACAO',
            'severity' => FiscalFindingSeverity::Medium->value,
            'title' => 'Retificação detectada',
            'detail' => 'Nova versão de evidência criada; artefato anterior preservado.',
            'situation' => FiscalSituation::Attention->value,
            'creates_pending' => false,
        ]];
    }
}
