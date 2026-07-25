<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\GuideEmissionClient;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Guides\DTO\GuideReconcileRequest;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliação de emissões em UNKNOWN_RESULT / RECONCILING.
 * Bloqueia retry de emissão até resultado definitivo.
 */
final class GuideReconciliationService
{
    public function __construct(
        private readonly GuideEmissionClient $client,
        private readonly GuideStorageService $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{guide:TaxGuide,version:TaxGuideVersion,outcome:string}
     */
    public function reconcile(Office $office, TaxGuideVersion $version): array
    {
        if ((int) $version->office_id !== (int) $office->id) {
            throw GuideException::notFound();
        }

        $status = $version->emission_status;
        if (! in_array($status, [TaxGuideEmissionStatus::UnknownResult, TaxGuideEmissionStatus::Reconciling], true)) {
            throw new GuideException(
                'Versão não está em estado de reconciliação.',
                'not_reconcilable',
                422,
            );
        }

        $max = (int) config('tax_guides.issuance.max_reconcile_attempts', 10);
        if ($version->reconcile_attempts >= $max) {
            throw new GuideException(
                'Limite de reconciliações atingido.',
                'reconcile_limit',
                409,
            );
        }

        $guide = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($version->tax_guide_id)
            ->firstOrFail();

        $version->emission_status = TaxGuideEmissionStatus::Reconciling;
        $version->reconcile_attempts = $version->reconcile_attempts + 1;
        $version->save();

        $result = $this->client->reconcile(new GuideReconcileRequest(
            officeId: (int) $office->id,
            clientId: (int) $guide->client_id,
            systemCode: $guide->system_code,
            serviceCode: $guide->service_code,
            operationCode: $guide->operation_code,
            idempotencyKey: $version->idempotency_key,
            correlationId: $version->correlation_id,
            remoteProtocol: $version->remote_protocol,
            competencePeriodKey: $guide->competence_period_key,
            debitRef: $guide->debit_ref,
        ));

        return DB::transaction(function () use ($office, $guide, $version, $result): array {
            $version = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($result->outcome === 'FOUND' && $result->emission !== null) {
                $stored = $this->storage->storeDocument(
                    (int) $office->id,
                    $result->emission->documentBytes,
                    $result->emission->contentType,
                );

                $version->fill([
                    'emission_status' => TaxGuideEmissionStatus::Confirmed,
                    'identifier_code' => $result->emission->identifierCode,
                    'amount_cents' => $result->emission->amountCents,
                    'due_at' => $result->emission->dueAtIso
                        ? CarbonImmutable::parse($result->emission->dueAtIso)
                        : null,
                    'valid_until' => $result->emission->validUntilIso
                        ? CarbonImmutable::parse($result->emission->validUntilIso)
                        : null,
                    'content_sha256' => $stored['content_sha256'],
                    'vault_object_id' => $stored['vault_object_id'],
                    'content_type' => $stored['content_type'],
                    'byte_size' => $stored['byte_size'],
                    'remote_protocol' => $result->emission->remoteProtocol ?? $version->remote_protocol,
                    'is_current' => true,
                    'finished_at' => CarbonImmutable::now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);
                $version->save();

                $guide->forceFill([
                    'current_version_id' => $version->id,
                    'amount_cents' => $version->amount_cents,
                    'due_at' => $version->due_at,
                    'identifier_code' => $version->identifier_code,
                    'payment_status' => $guide->payment_status === TaxGuidePaymentStatus::Unknown
                        ? TaxGuidePaymentStatus::NotConfirmed
                        : $guide->payment_status,
                ])->save();

                $this->audit->record(
                    action: 'tax_guide.reconcile.found',
                    result: 'SUCCESS',
                    subject: $version,
                    context: ['tax_guide_id' => $guide->id],
                    officeId: (int) $office->id,
                );

                return ['guide' => $guide->fresh(), 'version' => $version->fresh(), 'outcome' => 'FOUND'];
            }

            if ($result->outcome === 'REJECTED') {
                $version->emission_status = TaxGuideEmissionStatus::Rejected;
                $version->error_code = $result->errorCode ?? 'REMOTE_REJECTED';
                $version->error_message = $result->errorMessage;
                $version->finished_at = CarbonImmutable::now();
                $version->save();

                $this->audit->record(
                    action: 'tax_guide.reconcile.rejected',
                    result: 'REJECTED',
                    subject: $version,
                    context: ['tax_guide_id' => $guide->id],
                    officeId: (int) $office->id,
                );

                return ['guide' => $guide->fresh(), 'version' => $version->fresh(), 'outcome' => 'REJECTED'];
            }

            // STILL_UNKNOWN / NOT_FOUND → permanece bloqueado
            $delay = (int) config('tax_guides.issuance.reconcile_after_seconds', 60);
            $version->emission_status = TaxGuideEmissionStatus::UnknownResult;
            $version->reconcile_after = CarbonImmutable::now()->addSeconds($delay * max(1, $version->reconcile_attempts));
            $version->error_code = 'UNKNOWN_RESULT';
            $version->save();

            $this->audit->record(
                action: 'tax_guide.reconcile.still_unknown',
                result: 'UNKNOWN_RESULT',
                subject: $version,
                context: [
                    'tax_guide_id' => $guide->id,
                    'attempts' => $version->reconcile_attempts,
                    'outcome' => $result->outcome,
                ],
                officeId: (int) $office->id,
            );

            return ['guide' => $guide->fresh(), 'version' => $version->fresh(), 'outcome' => $result->outcome];
        });
    }

    /**
     * Versões elegíveis a reconciliação (scheduler/job).
     *
     * @return list<TaxGuideVersion>
     */
    public function dueVersions(int $limit = 50): array
    {
        return TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->whereIn('emission_status', [
                TaxGuideEmissionStatus::UnknownResult->value,
                TaxGuideEmissionStatus::Reconciling->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('reconcile_after')
                    ->orWhere('reconcile_after', '<=', now());
            })
            ->orderBy('reconcile_after')
            ->limit($limit)
            ->get()
            ->all();
    }
}
