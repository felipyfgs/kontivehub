<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\GuideEmissionClient;
use App\Enums\SerproUsageResult;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Guides\DTO\GuideEmissionRequest;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Services\Fiscal\Guides\Exceptions\GuideTransportTimeoutException;
use App\Services\Serpro\Catalog\OperationCoordinateResolver;
use App\Services\Serpro\Catalog\OperationKeyMap;
use App\Services\Serpro\Usage\UsageLedgerService;
use App\Services\Serpro\Usage\UsageReserveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emissão idempotente de guias com storage seguro, UNKNOWN_RESULT e substituição.
 * Mutações OFF por default (FeatureFlags guias + high-risk gate).
 */
final class GuideIssuanceService
{
    public function __construct(
        private readonly GuideCatalog $catalog,
        private readonly GuideHighRiskGate $highRisk,
        private readonly GuideEmissionClient $client,
        private readonly GuideStorageService $storage,
        private readonly UsageLedgerService $usage,
        private readonly AuditLogger $audit,
        private readonly OperationCoordinateResolver $coordinates,
    ) {}

    /**
     * Preflight: resumo, custo estimado, risco e requisitos de confirmação.
     *
     * @return array<string, mixed>
     */
    public function preflight(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $competencePeriodKey,
        ?string $debitRef,
        ?int $amountCents,
        ?User $user,
    ): array {
        $this->assertClientTenant($office, $client);
        $op = $this->catalog->resolve($systemCode, $serviceCode, $operationCode);
        $risk = $this->highRisk->resolveRisk($op['risk'], $amountCents);

        $idem = GuideIdempotency::emissionKey(
            (int) $office->id,
            (int) $client->id,
            $op['system'],
            $op['service'],
            $op['operation'],
            $competencePeriodKey,
            $debitRef,
        );

        $existing = TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $idem)
            ->first();

        // Preflight não grava reserva no ledger — só classifica custo estimado se possível.
        $usageEstimate = [
            'estimated_cost_micros' => null,
            'consumption_class' => null,
            'note' => 'Estimativa detalhada na reserva real da emissão',
        ];

        $gate = $this->highRisk->evaluate(
            $risk,
            $user,
            explicitConfirmation: false,
            confirmationSummary: null,
            mutating: true,
        );

        return [
            'operation' => [
                'system_code' => $op['system'],
                'service_code' => $op['service'],
                'operation_code' => $op['operation'],
                'label' => $op['label'],
            ],
            'client_id' => $client->id,
            'competence_period_key' => $competencePeriodKey,
            'debit_ref' => $debitRef,
            'amount_cents' => $amountCents,
            'risk_level' => $risk->value,
            'requires_reinforced_confirmation' => $risk->requiresReinforcedConfirmation(),
            'requires_recent_2fa' => $risk->requiresReinforcedConfirmation(),
            'has_recent_challenge' => $this->highRisk->hasRecentChallenge(),
            'idempotency_key' => $idem,
            'existing_version_id' => $existing?->id,
            'existing_emission_status' => $existing?->emission_status?->value,
            'usage' => $usageEstimate,
            'gate' => [
                'would_allow_with_confirmation' => ! in_array('mutating_disabled', $gate['codes'], true)
                    && ! in_array('module_disabled', $gate['codes'], true)
                    && ! in_array('role_required', $gate['codes'], true)
                    && ! in_array('two_factor_required', $gate['codes'], true)
                    && ! in_array('password_confirmation_required', $gate['codes'], true),
                'codes' => $gate['codes'],
                'reasons' => $gate['reasons'],
            ],
            'consequences' => [
                'mutates_remote' => true,
                'payment_not_inferred' => true,
                'may_incur_serpro_cost' => true,
            ],
        ];
    }

    /**
     * Emite guia (ou reutiliza/substitui conforme regras).
     *
     * @param  array<string, mixed>|null  $confirmationSummary
     * @return array{guide:TaxGuide,version:TaxGuideVersion,reused:bool,substituted:bool}
     */
    public function issue(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $competencePeriodKey,
        ?string $debitRef,
        ?int $amountCents,
        ?string $dueAtIso,
        User $user,
        bool $explicitConfirmation,
        ?array $confirmationSummary,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        bool $forceReissue = false,
        array $operationData = [],
    ): array {
        $this->assertClientTenant($office, $client);
        $op = $this->catalog->resolve($systemCode, $serviceCode, $operationCode);
        $operationKey = OperationKeyMap::resolve(
            null,
            $op['system'],
            $op['service'],
            $op['operation'],
        );
        $official = $operationKey !== null
            ? $this->coordinates->resolveExecutable($operationKey)
            : null;

        if (! $this->catalog->isEmissionOperation($op['operation'])) {
            throw new GuideException('Operação não é de emissão de guia.', 'not_emission_operation');
        }

        $risk = $this->highRisk->resolveRisk($op['risk'], $amountCents);

        // Gate ANTES de reservar consumo / chamar fonte
        $this->highRisk->assertAllowed(
            $risk,
            $user,
            $explicitConfirmation,
            $confirmationSummary,
            mutating: true,
        );

        $idem = $idempotencyKey ?: GuideIdempotency::emissionKey(
            (int) $office->id,
            (int) $client->id,
            $op['system'],
            $op['service'],
            $op['operation'],
            $competencePeriodKey,
            $debitRef,
        );

        $correlationId ??= (string) Str::uuid();

        // Idempotência: mesma chave já processada
        $existingVersion = TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $idem)
            ->first();

        if ($existingVersion !== null) {
            if ($existingVersion->emission_status->blocksRetry()) {
                throw GuideException::retryBlocked(
                    'Emissão com resultado incerto ou em voo — reconcilie antes de repetir.',
                );
            }

            if ($existingVersion->emission_status === TaxGuideEmissionStatus::Confirmed
                && $existingVersion->hasStoredDocument()
                && ! $forceReissue
            ) {
                $guide = TaxGuide::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereKey($existingVersion->tax_guide_id)
                    ->firstOrFail();

                $this->audit->record(
                    action: 'tax_guide.issue.reuse',
                    result: 'SUCCESS',
                    subject: $existingVersion,
                    context: ['idempotency_key_hash' => substr(hash('sha256', $idem), 0, 16)],
                    userId: $user->id,
                    officeId: (int) $office->id,
                );

                return ['guide' => $guide, 'version' => $existingVersion, 'reused' => true, 'substituted' => false];
            }
        }

        // Guia lógica vigente com documento válido → reutilizar se mesma identidade e não force
        $logical = GuideIdempotency::logicalKey(
            (int) $client->id,
            $op['system'],
            $op['service'],
            $competencePeriodKey,
            $debitRef,
        );

        $guide = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('logical_key', $logical)
            ->first();

        if ($guide !== null && ! $forceReissue) {
            $current = $guide->currentVersion()
                ->withoutGlobalScopes()
                ->first();

            if (
                $current !== null
                && $current->emission_status === TaxGuideEmissionStatus::Confirmed
                && $current->hasStoredDocument()
                && $this->isStillValid($current)
                && ! $this->differsFromCurrent($current, $amountCents, $dueAtIso)
            ) {
                return ['guide' => $guide, 'version' => $current, 'reused' => true, 'substituted' => false];
            }

            // Bloqueio se versão atual está em estado incerto
            if ($current !== null && $current->emission_status->blocksRetry()) {
                throw GuideException::retryBlocked();
            }
        }

        return DB::transaction(function () use (
            $office,
            $client,
            $op,
            $competencePeriodKey,
            $debitRef,
            $amountCents,
            $dueAtIso,
            $user,
            $confirmationSummary,
            $idem,
            $correlationId,
            $risk,
            $guide,
            $logical,
            $forceReissue,
            $operationData,
            $operationKey,
            $official,
        ): array {
            $guide = $guide ?? TaxGuide::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'system_code' => $op['system'],
                'service_code' => $op['service'],
                'operation_code' => $op['operation'],
                'competence_period_key' => $competencePeriodKey,
                'debit_ref' => $debitRef,
                'logical_key' => $logical,
                'payment_status' => TaxGuidePaymentStatus::Unknown,
                'created_by' => $user->id,
            ]);

            $previous = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('tax_guide_id', $guide->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $nextVersion = ($previous?->version_number ?? 0) + 1;
            $substituted = $previous !== null
                && $previous->emission_status === TaxGuideEmissionStatus::Confirmed
                && ($forceReissue || $this->differsFromCurrent($previous, $amountCents, $dueAtIso));

            // Se reemissão, slot distinto evita colidir com idempotency_key da versão anterior
            $versionIdem = $idem;
            if ($previous !== null && ($forceReissue || $substituted)) {
                $versionIdem = GuideIdempotency::emissionKey(
                    (int) $office->id,
                    (int) $client->id,
                    $op['system'],
                    $op['service'],
                    $op['operation'],
                    $competencePeriodKey,
                    $debitRef,
                    GuideIdempotency::reissueSlot('reissue', $nextVersion),
                );
            }

            // Reserva de uso com a mesma chave da versão (idempotente)
            $reserve = $this->usage->reserve(new UsageReserveRequest(
                officeId: (int) $office->id,
                idempotencyKey: $versionIdem,
                systemCode: $op['system'],
                serviceCode: $op['service'],
                operationCode: $op['operation'],
                quantity: 1,
                clientId: (int) $client->id,
                correlationId: $correlationId,
                operationKey: $operationKey,
                functionalRoute: $official !== null ? $official['route']->value : null,
                requestTag: $operationKey !== null ? substr(hash('sha256', implode('|', [
                    (string) $office->id,
                    (string) $client->id,
                    $operationKey,
                    $versionIdem,
                    $correlationId,
                ])), 0, 32) : null,
            ));

            if (! $reserve->allowed) {
                throw new GuideException(
                    'Orçamento/uso SERPRO bloqueou a emissão.',
                    'usage_blocked',
                    402,
                    ['block_reason' => $reserve->reservation->block_reason],
                );
            }

            $version = TaxGuideVersion::query()->create([
                'office_id' => $office->id,
                'tax_guide_id' => $guide->id,
                'version_number' => $nextVersion,
                'is_current' => false,
                'emission_status' => TaxGuideEmissionStatus::Pending,
                'replaces_version_id' => $substituted ? $previous?->id : null,
                'amount_cents' => $amountCents,
                'due_at' => $dueAtIso ? CarbonImmutable::parse($dueAtIso) : null,
                'idempotency_key' => $versionIdem,
                'correlation_id' => $correlationId,
                'usage_reservation_id' => $reserve->reservation->id,
                'risk_level' => $risk,
                'confirmation_summary' => $confirmationSummary,
                'confirmed_by_user_id' => $user->id,
                'confirmed_at' => CarbonImmutable::now(),
                'issued_by' => $user->id,
            ]);

            $version->emission_status = TaxGuideEmissionStatus::Sent;
            $version->sent_at = CarbonImmutable::now();
            $version->save();

            try {
                $result = $this->client->emit(new GuideEmissionRequest(
                    officeId: (int) $office->id,
                    clientId: (int) $client->id,
                    systemCode: $op['system'],
                    serviceCode: $op['service'],
                    operationCode: $op['operation'],
                    competencePeriodKey: $competencePeriodKey,
                    debitRef: $debitRef,
                    amountCents: $amountCents,
                    dueAtIso: $dueAtIso,
                    idempotencyKey: $versionIdem,
                    correlationId: $correlationId,
                    payload: $operationData,
                ));
            } catch (GuideTransportTimeoutException $e) {
                return $this->markUnknownResult(
                    $guide,
                    $version,
                    $reserve->reservation,
                    $e,
                    $user,
                    $previous,
                );
            } catch (\Throwable $e) {
                // Falha antes/sem certeza de processamento remoto → finalize como erro e rejeita versão
                $this->usage->finalize(
                    $reserve->reservation,
                    SerproUsageResult::TransportError,
                    latencyMs: null,
                    possiblyBillable: true,
                );
                $version->emission_status = TaxGuideEmissionStatus::Rejected;
                $version->error_code = 'SOURCE_ERROR';
                $version->error_message = $this->sanitizeError($e->getMessage());
                $version->finished_at = CarbonImmutable::now();
                $version->save();

                throw new GuideException(
                    'Falha na fonte ao emitir guia.',
                    'source_error',
                    502,
                    ['version_id' => $version->id],
                );
            }

            $stored = $this->storage->storeDocument(
                (int) $office->id,
                $result->documentBytes,
                $result->contentType,
            );

            $this->usage->finalize(
                $reserve->reservation,
                SerproUsageResult::Success,
                latencyMs: $result->latencyMs,
            );

            $version->fill([
                'emission_status' => TaxGuideEmissionStatus::Confirmed,
                'identifier_code' => $result->identifierCode,
                'amount_cents' => $result->amountCents,
                'due_at' => $result->dueAtIso ? CarbonImmutable::parse($result->dueAtIso) : null,
                'valid_until' => $result->validUntilIso ? CarbonImmutable::parse($result->validUntilIso) : null,
                'content_sha256' => $stored['content_sha256'],
                'vault_object_id' => $stored['vault_object_id'],
                'content_type' => $stored['content_type'],
                'byte_size' => $stored['byte_size'],
                'remote_protocol' => $result->remoteProtocol,
                'is_current' => true,
                'finished_at' => CarbonImmutable::now(),
                'metadata' => [
                    'simulated' => $result->simulated,
                ],
            ]);
            $version->save();

            if ($previous !== null && $previous->id !== $version->id) {
                $previous->is_current = false;
                if ($substituted || $previous->emission_status === TaxGuideEmissionStatus::Confirmed) {
                    $previous->emission_status = TaxGuideEmissionStatus::Superseded;
                    $previous->superseded_by_version_id = $version->id;
                }
                $previous->save();
            }

            // Pagamento permanece independente — NÃO marcar pago
            $guide->forceFill([
                'current_version_id' => $version->id,
                'amount_cents' => $version->amount_cents,
                'due_at' => $version->due_at,
                'identifier_code' => $version->identifier_code,
                // payment_status intocado se já houver confirmação; senão NOT_CONFIRMED
                'payment_status' => $guide->payment_status === TaxGuidePaymentStatus::Confirmed
                    || $guide->payment_status === TaxGuidePaymentStatus::Partial
                    ? $guide->payment_status
                    : TaxGuidePaymentStatus::NotConfirmed,
            ])->save();

            $this->audit->record(
                action: $substituted ? 'tax_guide.issue.substitute' : 'tax_guide.issue.confirm',
                result: 'SUCCESS',
                subject: $version,
                context: [
                    'tax_guide_id' => $guide->id,
                    'version_number' => $version->version_number,
                    'substituted' => $substituted,
                    'amount_cents' => $version->amount_cents,
                    'content_sha256' => $version->content_sha256,
                    // sem bytes, vault path, CNPJ
                ],
                userId: $user->id,
                officeId: (int) $office->id,
            );

            return [
                'guide' => $guide->fresh(),
                'version' => $version->fresh(),
                'reused' => false,
                'substituted' => $substituted,
            ];
        });
    }

    /**
     * @return array{guide:TaxGuide,version:TaxGuideVersion,reused:bool,substituted:bool}
     */
    private function markUnknownResult(
        TaxGuide $guide,
        TaxGuideVersion $version,
        $reservation,
        GuideTransportTimeoutException $e,
        User $user,
        ?TaxGuideVersion $previous,
    ): array {
        $blockSeconds = (int) config('tax_guides.issuance.unknown_result_retry_block_seconds', 300);
        $reconcileAfter = (int) config('tax_guides.issuance.reconcile_after_seconds', 60);

        $this->usage->finalize(
            $reservation,
            SerproUsageResult::Timeout,
            possiblyBillable: true,
        );

        $version->emission_status = TaxGuideEmissionStatus::UnknownResult;
        $version->error_code = 'UNKNOWN_RESULT';
        $version->error_message = $this->sanitizeError($e->getMessage());
        $version->reconcile_after = CarbonImmutable::now()->addSeconds($reconcileAfter);
        $version->finished_at = CarbonImmutable::now();
        $version->is_current = true;
        $version->metadata = array_merge($version->metadata ?? [], [
            'retry_blocked_until' => CarbonImmutable::now()->addSeconds($blockSeconds)->toIso8601String(),
            'correlation_id' => $e->correlationId ?? $version->correlation_id,
        ]);
        $version->save();

        if ($previous !== null && $previous->id !== $version->id) {
            // Mantém histórico; não sobrescreve artefato anterior
            $previous->is_current = false;
            $previous->save();
        }

        $guide->forceFill([
            'current_version_id' => $version->id,
            // pagamento intocado
        ])->save();

        $this->audit->record(
            action: 'tax_guide.issue.unknown_result',
            result: 'UNKNOWN_RESULT',
            subject: $version,
            context: [
                'tax_guide_id' => $guide->id,
                'version_id' => $version->id,
                'retry_blocked' => true,
                'reconcile_after' => $version->reconcile_after?->toIso8601String(),
            ],
            userId: $user->id,
            officeId: (int) $guide->office_id,
        );

        return [
            'guide' => $guide->fresh(),
            'version' => $version->fresh(),
            'reused' => false,
            'substituted' => false,
        ];
    }

    private function isStillValid(TaxGuideVersion $version): bool
    {
        if ($version->valid_until !== null && $version->valid_until->isPast()) {
            return false;
        }

        return true;
    }

    private function differsFromCurrent(TaxGuideVersion $current, ?int $amountCents, ?string $dueAtIso): bool
    {
        if ($amountCents !== null && $current->amount_cents !== null && (int) $current->amount_cents !== $amountCents) {
            return true;
        }
        if ($dueAtIso !== null && $current->due_at !== null) {
            $due = CarbonImmutable::parse($dueAtIso);
            if (! $current->due_at->equalTo($due) && $current->due_at->toDateString() !== $due->toDateString()) {
                return true;
            }
        }

        return false;
    }

    private function assertClientTenant(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw GuideException::notFound('Cliente não encontrado.');
        }
    }

    private function sanitizeError(string $message): string
    {
        $clean = preg_replace('/[A-Za-z0-9+\/]{40,}={0,2}/', '[redacted]', $message) ?? $message;

        return mb_substr($clean, 0, 500);
    }
}
