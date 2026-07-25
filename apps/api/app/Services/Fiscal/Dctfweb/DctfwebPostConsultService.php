<?php

namespace App\Services\Fiscal\Dctfweb;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebCategory;
use App\Enums\DctfwebConsultOutcome;
use App\Enums\DctfwebDeclarationState;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Models\DctfwebConsultObservation;
use App\Models\DctfwebDeclaration;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebEvidenceVersioningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Projeta CONSRECIBO32 de forma fail-closed: PDF no cofre, observação imutável, estado.
 */
final class DctfwebPostConsultService
{
    public function __construct(
        private readonly DctfwebConsReciboCodec $codec,
        private readonly DctfwebDeclarationService $declarations,
        private readonly DctfwebEvidenceVersioningService $versioning,
        private readonly DctfwebDeclarationStateResolver $stateResolver,
    ) {}

    /**
     * @return array{result:FiscalAdapterResult,sanitized_dados:mixed}
     */
    public function handle(
        FiscalAdapterRequest $request,
        IntegraResponse $response,
        FiscalAdapterResult $result,
    ): array {
        $periodKey = $this->resolveExpectedPeriodKey($request);
        $category = DctfwebCategory::default();
        $pa = null;
        try {
            $pa = DctfwebPeriod::parse($periodKey);
        } catch (\Throwable) {
            return $this->unverified($result, $request, $periodKey, $category, 'EXPECTED_PERIOD_UNAVAILABLE', $response);
        }

        if (! $response->success || $result->result !== FiscalRunResult::Success) {
            $failReason = $response->errorCode ?? 'UPSTREAM_ERROR';
            $this->markExistingExpectedProjectionUnverified($request, $periodKey, $failReason);
            $this->recordObservation(
                request: $request,
                periodKey: $periodKey,
                pa: $pa,
                category: $category,
                outcome: DctfwebConsultOutcome::Failed,
                productive: false,
                documentStored: false,
                state: DctfwebDeclarationState::Unverified,
                reason: $failReason,
                message: $this->sanitizeMessage($response->errorMessage),
                provenance: $this->provenance($response),
            );

            return ['result' => $result, 'sanitized_dados' => null];
        }

        if ($response->simulated || ! $this->isRealProductive($response)) {
            $this->markExistingExpectedProjectionUnverified($request, $periodKey, 'SOURCE_NOT_SERPRO_REAL');
            $statePack = $this->stateResolver->resolve(
                declarationForExpectedPa: null,
                lastProductiveConsultedAt: null,
                projection: $this->findProjection($request, $periodKey),
                simulated: false,
            );
            $this->recordObservation(
                request: $request,
                periodKey: $periodKey,
                pa: $pa,
                category: $category,
                outcome: DctfwebConsultOutcome::Failed,
                productive: false,
                documentStored: false,
                state: $statePack['state'],
                reason: 'SOURCE_NOT_SERPRO_REAL',
                message: null,
                provenance: $this->provenance($response),
            );

            return $this->withNormalized($result, [
                'period_key' => $periodKey,
                'category' => $category->value,
                'declaration_state' => $statePack['state']->value,
                'declaration_state_reason' => $statePack['reason'],
                'productive' => false,
                'source_rejected' => $response->hasSimulatedSource(),
            ], FiscalSituation::Unknown);
        }

        $dados = $response->dados ?? ($response->body['dados'] ?? $response->body);
        try {
            $extracted = $this->codec->extractPdfField($dados);
            $pdfBytes = $this->codec->decodePdf($extracted['base64']);
        } catch (\Throwable $exception) {
            // Ausência de PDF pode ser "não encontrado" (mensagens de negócio) ou documento inválido.
            $notFound = $this->looksLikeNotFound($response);
            $outcome = $notFound ? DctfwebConsultOutcome::NotFound : DctfwebConsultOutcome::InvalidDocument;
            $reason = $notFound ? 'RECEIPT_NOT_FOUND' : 'INVALID_OR_MISSING_PDF';

            $projection = $this->findProjection($request, $periodKey);
            $observedAt = CarbonImmutable::now();
            $declaration = $this->declarations->findOrCreate(
                $request->office,
                $request->client,
                $periodKey,
                $category,
            );

            $statePack = $this->stateResolver->resolve(
                declarationForExpectedPa: $notFound ? null : $declaration,
                lastProductiveConsultedAt: $notFound ? $observedAt : null,
                projection: $projection,
                responseIncomplete: ! $notFound,
                documentValid: false,
            );

            if ($notFound) {
                $this->applyState($declaration, $statePack, $observedAt, null);
                $this->touchProjection($request, $periodKey, $declaration, $statePack, $observedAt, productive: true);
            } else {
                // Documento inválido / incompleto não é evidência produtiva: demove last-known-good.
                $this->markExistingExpectedProjectionUnverified($request, $periodKey, $reason);
            }

            $this->recordObservation(
                request: $request,
                periodKey: $periodKey,
                pa: $pa,
                category: $category,
                outcome: $outcome,
                productive: $notFound,
                documentStored: false,
                state: $statePack['state'],
                reason: $reason,
                message: $this->sanitizeMessage($exception->getMessage()),
                provenance: $this->provenance($response),
                declarationId: $declaration->id,
            );

            Log::warning('dctfweb.consrecibo.pdf_failed', [
                'run_id' => $request->run->id,
                'reason_code' => $reason,
            ]);

            $situation = match ($statePack['state']) {
                DctfwebDeclarationState::Current,
                DctfwebDeclarationState::NoMovementValid => FiscalSituation::UpToDate,
                DctfwebDeclarationState::DueWithinDeadline,
                DctfwebDeclarationState::OverdueNotFound => FiscalSituation::Pending,
                DctfwebDeclarationState::Unverified => FiscalSituation::Unknown,
            };

            return $this->withNormalized($result, [
                'period_key' => $periodKey,
                'category' => $category->value,
                'declaration_state' => $statePack['state']->value,
                'declaration_state_reason' => $statePack['reason'],
                'productive' => $notFound,
                'document_stored' => false,
            ], $situation, evidenceBytes: null);
        }

        $hints = $this->codec->parsePdfHints($pdfBytes);
        $declaration = $this->declarations->findOrCreate(
            $request->office,
            $request->client,
            $periodKey,
            $category,
        );

        $bodyType = strtoupper((string) ($response->body['tipo'] ?? $response->body['declaration_type'] ?? ''));
        $declaredType = $hints['declaration_type']
            ?? ($bodyType === 'RETIFICADORA' ? 'RECTIFICADORA' : ($bodyType === 'ORIGINAL' ? 'ORIGINAL' : null));

        $stored = $this->versioning->storeVersioned(
            run: $request->run,
            declaration: $declaration,
            kind: DctfwebArtifactKind::Recibo,
            bytes: $pdfBytes,
            contentType: 'application/pdf',
            sourceVersion: isset($response->body['versao']) ? (string) $response->body['versao'] : '1.0',
            declarationType: $declaredType ?? $declaration->declaration_type,
            metadata: [
                'source_path' => $extracted['path'],
                'parser_version' => $hints['parser_version'],
                'byte_size' => strlen($pdfBytes),
                'content_sha256' => hash('sha256', $pdfBytes),
            ],
        );

        $receipt = $this->stringFromBody($response->body, ['recibo', 'numeroRecibo', 'receipt_number', 'numero_recibo'])
            ?? $hints['receipt_number'];
        $isRetificadora = $declaredType === 'RECTIFICADORA'
            || $stored['retification']
            || $this->boolFromBody($response->body, ['retificadora', 'is_rectification']);
        $noMovement = $hints['no_movement'];
        $observedAt = CarbonImmutable::now();

        $declaration->forceFill([
            'category' => $category,
            'declaration_type' => $isRetificadora ? 'RECTIFICADORA' : ($hints['declaration_type'] ?? $declaration->declaration_type ?? 'ORIGINAL'),
            'transmission_status' => $isRetificadora
                ? DctfwebTransmissionStatus::Rectified
                : DctfwebTransmissionStatus::Transmitted,
            'receipt_number' => $receipt ?? $declaration->receipt_number ?? ('PDF-'.$stored['version']->id),
            'transmitted_at' => $declaration->transmitted_at ?? $observedAt,
            'official_at' => $declaration->official_at ?? $observedAt,
            'no_movement' => $noMovement,
            'coverage' => FiscalCoverage::Full,
            // payment_status permanece intocado
        ])->save();

        $projection = $this->findProjection($request, $periodKey);
        $statePack = $this->stateResolver->resolve(
            declarationForExpectedPa: $declaration->fresh(),
            lastProductiveConsultedAt: $observedAt,
            projection: $projection,
            noMovement: $noMovement === true,
            documentValid: true,
        );
        $this->applyState($declaration, $statePack, $observedAt, $noMovement);
        $this->touchProjection($request, $periodKey, $declaration->fresh(), $statePack, $observedAt, productive: true);

        $descriptor = [
            'sanitized' => true,
            'available' => true,
            'kind' => DctfwebArtifactKind::Recibo->value,
            'content_type' => 'application/pdf',
            'byte_size' => strlen($pdfBytes),
            'evidence_version_id' => $stored['version']->id,
            'download_path' => '/api/v1/fiscal/dctfweb/clients/'.$request->client->id
                .'/evidence/'.$stored['version']->id.'/download',
        ];
        $sanitized = $this->codec->sanitizeDados($dados, $descriptor);

        $this->recordObservation(
            request: $request,
            periodKey: $periodKey,
            pa: $pa,
            category: $category,
            outcome: DctfwebConsultOutcome::Found,
            productive: true,
            documentStored: true,
            state: $statePack['state'],
            reason: $statePack['reason'],
            message: null,
            provenance: $this->provenance($response),
            declarationId: $declaration->id,
            metadata: [
                'evidence_version_id' => $stored['version']->id,
                'retification' => $stored['retification'],
                'no_movement' => $noMovement,
            ],
        );

        $situation = match ($statePack['state']) {
            DctfwebDeclarationState::Current,
            DctfwebDeclarationState::NoMovementValid => FiscalSituation::UpToDate,
            DctfwebDeclarationState::DueWithinDeadline,
            DctfwebDeclarationState::OverdueNotFound => FiscalSituation::Pending,
            DctfwebDeclarationState::Unverified => FiscalSituation::Unknown,
        };

        return $this->withNormalized(
            $result,
            [
                'period_key' => $periodKey,
                'category' => $category->value,
                'artifact_kind' => DctfwebArtifactKind::Recibo->value,
                'receipt_number' => $declaration->fresh()->receipt_number,
                'transmission_status' => $declaration->fresh()->transmission_status?->value,
                'declaration_state' => $statePack['state']->value,
                'declaration_state_reason' => $statePack['reason'],
                'calendar_verified' => $statePack['calendar_verified'],
                'no_movement' => $noMovement,
                'retification' => $stored['retification'],
                'evidence_version' => $stored['version']->version,
                'evidence_version_id' => $stored['version']->id,
                'productive' => true,
                'document_stored' => true,
                'simulated' => false,
            ],
            $situation,
            evidenceBytes: null, // bytes já no cofre; não re-serializar Base64
            contentType: 'application/pdf',
            sanitizedDados: $sanitized,
        );
    }

    public function resolveExpectedPeriodKey(FiscalAdapterRequest $request): string
    {
        $progress = is_array($request->progress) ? $request->progress : [];
        // PA congelado no progress vence qualquer period_key comercial.
        foreach (['expected_period_key', 'period_key'] as $key) {
            if (! empty($progress[$key]) && is_string($progress[$key])) {
                return DctfwebPeriod::toPeriodKey(DctfwebPeriod::parse($progress[$key]));
            }
        }
        if (! empty($progress['anoPA']) && ! empty($progress['mesPA'])) {
            return DctfwebPeriod::periodKeyFromParts((string) $progress['anoPA'], (string) $progress['mesPA']);
        }
        if (! empty($progress['expected_periodo_apuracao']) && is_string($progress['expected_periodo_apuracao'])) {
            return DctfwebPeriod::toPeriodKey(DctfwebPeriod::parse($progress['expected_periodo_apuracao']));
        }

        if ($request->competence?->period_key) {
            return DctfwebPeriod::toPeriodKey(DctfwebPeriod::parse($request->competence->period_key));
        }

        $tz = is_string($request->office->timezone) && $request->office->timezone !== ''
            ? $request->office->timezone
            : 'America/Sao_Paulo';

        return DctfwebPeriod::toPeriodKey(DctfwebPeriod::expectedPa(null, $tz));
    }

    /**
     * @return array{result:FiscalAdapterResult,sanitized_dados:mixed}
     */
    private function unverified(
        FiscalAdapterResult $result,
        FiscalAdapterRequest $request,
        string $periodKey,
        DctfwebCategory $category,
        string $reason,
        IntegraResponse $response,
    ): array {
        try {
            $pa = DctfwebPeriod::parse($periodKey);
        } catch (\Throwable) {
            $pa = DctfwebPeriod::expectedPa();
            $periodKey = DctfwebPeriod::toPeriodKey($pa);
        }

        $this->markExistingExpectedProjectionUnverified($request, $periodKey, $reason);
        $this->recordObservation(
            request: $request,
            periodKey: $periodKey,
            pa: $pa,
            category: $category,
            outcome: DctfwebConsultOutcome::Incomplete,
            productive: false,
            documentStored: false,
            state: DctfwebDeclarationState::Unverified,
            reason: $reason,
            message: null,
            provenance: $this->provenance($response),
        );

        return $this->withNormalized($result, [
            'period_key' => $periodKey,
            'category' => $category->value,
            'declaration_state' => DctfwebDeclarationState::Unverified->value,
            'declaration_state_reason' => $reason,
            'productive' => false,
        ], FiscalSituation::Unknown);
    }

    /**
     * Fail-closed: consulta não produtiva não mantém CURRENT/Em dia na carteira.
     * Só demove registros já existentes (não cria declaração/projeção fantasma).
     */
    private function markExistingExpectedProjectionUnverified(
        FiscalAdapterRequest $request,
        string $periodKey,
        string $reason,
    ): void {
        $projection = $this->findProjection($request, $periodKey);
        if ($projection !== null) {
            $metadata = is_array($projection->metadata) ? $projection->metadata : [];
            $metadata['dctfweb_declaration_state_reason'] = $reason;
            $projection->forceFill([
                'dctfweb_declaration_state' => DctfwebDeclarationState::Unverified,
                'dctfweb_calendar_verified' => false,
                'situation' => FiscalSituation::Unknown,
                'metadata' => $metadata,
            ])->save();
        }

        $declaration = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('period_key', $periodKey)
            ->where('category', DctfwebCategory::default()->value)
            ->first();

        if ($declaration !== null) {
            $declaration->forceFill([
                'declaration_state' => DctfwebDeclarationState::Unverified,
                'state_reason' => $reason,
                'calendar_verified' => false,
                'situation' => FiscalSituation::Unknown,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $dctfweb
     * @return array{result:FiscalAdapterResult,sanitized_dados:mixed}
     */
    private function withNormalized(
        FiscalAdapterResult $result,
        array $dctfweb,
        FiscalSituation $situation,
        ?string $evidenceBytes = null,
        string $contentType = 'application/json',
        mixed $sanitizedDados = null,
    ): array {
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        $normalized['dctfweb'] = $dctfweb;
        $normalized['period_key'] = $dctfweb['period_key'] ?? ($normalized['period_key'] ?? null);

        // Evidência pública = metadados sanitizados (sem Base64). Bytes do PDF ficam no cofre.
        $publicEvidence = $evidenceBytes;
        if ($publicEvidence === null || $publicEvidence === '') {
            $publicEvidence = json_encode([
                'dctfweb' => $dctfweb,
                'sanitized' => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $new = new FiscalAdapterResult(
            result: $result->result,
            situation: $situation,
            coverage: $result->coverage ?? FiscalCoverage::Full,
            evidenceBytes: $publicEvidence,
            evidenceContentType: $contentType === 'application/pdf' ? 'application/json' : $contentType,
            sourceVersion: $result->sourceVersion,
            normalized: $normalized,
            findings: $result->findings,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            shouldRequeue: $result->shouldRequeue,
            progressCursor: $result->progressCursor,
            progress: $result->progress,
            itemsProcessed: $result->itemsProcessed,
            pagesProcessed: $result->pagesProcessed,
            skipReason: $result->skipReason,
            requeueAfterSeconds: $result->requeueAfterSeconds,
        );

        return ['result' => $new, 'sanitized_dados' => $sanitizedDados];
    }

    /**
     * @param  array{
     *   state: DctfwebDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }  $statePack
     */
    private function applyState(
        DctfwebDeclaration $declaration,
        array $statePack,
        CarbonImmutable $observedAt,
        ?bool $noMovement,
    ): void {
        $situation = match ($statePack['state']) {
            DctfwebDeclarationState::Current,
            DctfwebDeclarationState::NoMovementValid => FiscalSituation::UpToDate,
            DctfwebDeclarationState::DueWithinDeadline,
            DctfwebDeclarationState::OverdueNotFound => FiscalSituation::Pending,
            DctfwebDeclarationState::Unverified => FiscalSituation::Unknown,
        };

        $declaration->forceFill([
            'declaration_state' => $statePack['state'],
            'state_reason' => $statePack['reason'],
            'calendar_verified' => $statePack['calendar_verified'],
            'calendar_version_code' => $statePack['calendar_version_code'],
            'due_at' => $statePack['due_at'],
            'last_productive_consulted_at' => $observedAt,
            'situation' => $situation,
            'no_movement' => $noMovement ?? $declaration->no_movement,
        ])->save();

        if ($declaration->competence_id) {
            $declaration->competence?->forceFill([
                'situation' => $situation,
                'coverage' => FiscalCoverage::Full,
            ])->save();
        }
    }

    /**
     * @param  array{
     *   state: DctfwebDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }  $statePack
     */
    private function touchProjection(
        FiscalAdapterRequest $request,
        string $periodKey,
        DctfwebDeclaration $declaration,
        array $statePack,
        CarbonImmutable $observedAt,
        bool $productive,
    ): void {
        $projection = $this->findOrEnsureProjection($request, $periodKey);
        if ($projection === null) {
            return;
        }

        $projection->forceFill([
            'dctfweb_declaration_state' => $statePack['state'],
            'dctfweb_last_declaration_id' => $declaration->id,
            'dctfweb_calendar_version_code' => $statePack['calendar_version_code'],
            'dctfweb_calendar_verified' => $statePack['calendar_verified'],
            'dctfweb_category' => DctfwebCategory::default()->value,
            'situation' => $declaration->situation,
            'due_at' => $statePack['due_at'] ?? $projection->due_at,
            ...(
                $productive
                    ? [
                        'last_valid_query_at' => $observedAt,
                        'last_valid_run_id' => $request->run->id,
                        'dctfweb_last_productive_consulted_at' => $observedAt,
                    ]
                    : []
            ),
        ])->save();
    }

    private function findProjection(FiscalAdapterRequest $request, string $periodKey): ?TaxObligationProjection
    {
        $definitionId = TaxObligationDefinition::query()->where('code', 'DCTFWEB')->value('id');
        if ($definitionId === null) {
            return null;
        }

        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('period_key', $periodKey)
            ->where('obligation_definition_id', $definitionId)
            ->first();
    }

    private function findOrEnsureProjection(FiscalAdapterRequest $request, string $periodKey): ?TaxObligationProjection
    {
        $existing = $this->findProjection($request, $periodKey);
        if ($existing !== null) {
            return $existing;
        }

        $definition = TaxObligationDefinition::query()->where('code', 'DCTFWEB')->first();
        if ($definition === null) {
            return null;
        }

        $pa = DctfwebPeriod::parse($periodKey);

        return TaxObligationProjection::query()->create([
            'office_id' => $request->office->id,
            'client_id' => $request->client->id,
            'obligation_definition_id' => $definition->id,
            'period_key' => $periodKey,
            'period_year' => (int) $pa->format('Y'),
            'period_month' => (int) $pa->format('m'),
            'situation' => FiscalSituation::Unknown,
            'dctfweb_category' => DctfwebCategory::default()->value,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordObservation(
        FiscalAdapterRequest $request,
        string $periodKey,
        CarbonImmutable $pa,
        DctfwebCategory $category,
        DctfwebConsultOutcome $outcome,
        bool $productive,
        bool $documentStored,
        DctfwebDeclarationState $state,
        string $reason,
        ?string $message,
        ?string $provenance,
        ?int $declarationId = null,
        ?array $metadata = null,
    ): void {
        DctfwebConsultObservation::query()->create([
            'office_id' => $request->office->id,
            'client_id' => $request->client->id,
            'declaration_id' => $declarationId,
            'run_id' => $request->run->id,
            'category' => $category,
            'period_key' => $periodKey,
            'ano_pa' => DctfwebPeriod::toAnoPa($pa),
            'mes_pa' => DctfwebPeriod::toMesPa($pa),
            'outcome' => $outcome,
            'provenance' => $provenance,
            'declaration_state' => $state,
            'productive' => $productive,
            'document_stored' => $documentStored,
            'reason' => $reason,
            'sanitized_message' => $message,
            'observed_at' => CarbonImmutable::now(),
            'metadata' => $metadata,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function isRealProductive(IntegraResponse $response): bool
    {
        return $response->success
            && ! $response->simulated
            && $response->sourceProvenance === FiscalSourceProvenance::SerproReal->value;
    }

    private function provenance(IntegraResponse $response): string
    {
        if ($response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value) {
            return FiscalSourceProvenance::SerproTrial->value;
        }

        if ($response->hasSimulatedSource()) {
            return FiscalSourceProvenance::Unverified->value;
        }

        if ($response->sourceProvenance === FiscalSourceProvenance::SerproReal->value) {
            return FiscalSourceProvenance::SerproReal->value;
        }

        return FiscalSourceProvenance::Unverified->value;
    }

    private function looksLikeNotFound(IntegraResponse $response): bool
    {
        $code = strtoupper((string) ($response->errorCode ?? ''));
        $msg = mb_strtoupper((string) ($response->errorMessage ?? ''));
        $bodyMsg = '';
        if (is_array($response->body['mensagens'] ?? null)) {
            foreach ($response->body['mensagens'] as $m) {
                if (is_array($m)) {
                    $bodyMsg .= ' '.mb_strtoupper((string) ($m['texto'] ?? $m['text'] ?? $m['mensagem'] ?? ''));
                    $bodyMsg .= ' '.mb_strtoupper((string) ($m['codigo'] ?? $m['code'] ?? ''));
                }
            }
        }

        $hay = $code.' '.$msg.' '.$bodyMsg;

        return str_contains($hay, 'NOT_FOUND')
            || str_contains($hay, 'NAO ENCONTR')
            || str_contains($hay, 'NÃO ENCONTR')
            || str_contains($hay, 'INEXISTENTE')
            || str_contains($hay, 'SEM RECIBO')
            || str_contains($hay, 'SEM DECLAR');
    }

    private function sanitizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        $clean = preg_replace('/[A-Za-z0-9+\/]{40,}={0,2}/', '[redacted]', $message) ?? $message;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, 255) ?: null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    private function stringFromBody(array $body, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($body[$k]) && is_scalar($body[$k]) && (string) $body[$k] !== '') {
                return (string) $body[$k];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    private function boolFromBody(array $body, array $keys): bool
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $body)) {
                continue;
            }
            $v = $body[$k];
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (int) $v === 1;
            }
            if (is_string($v)) {
                return in_array(strtoupper($v), ['1', 'TRUE', 'SIM', 'S', 'YES'], true);
            }
        }

        return false;
    }
}
