<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\TaxGuideDownloadTokenData;
use App\DTO\Fiscal\Mutations\TaxGuideIssuanceResultData;
use App\DTO\Fiscal\Mutations\TaxGuideIssueData;
use App\DTO\Fiscal\Mutations\TaxGuidePaymentResultData;
use App\DTO\Fiscal\Mutations\TaxGuidePreflightData;
use App\DTO\Fiscal\Mutations\TaxGuideReconcileResultData;
use App\Models\User;
use App\Services\Fiscal\Guides\GuideDownloadService;
use App\Services\Fiscal\Guides\GuideIssuanceService;
use App\Services\Fiscal\Guides\GuidePaymentService;
use App\Services\Fiscal\Guides\GuideQueryService;
use App\Services\Fiscal\Guides\GuideReconciliationService;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class OperateTaxGuideAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private GuideQueryService $queries,
        private GuideIssuanceService $issuance,
        private GuideDownloadService $downloads,
        private GuidePaymentService $payments,
        private GuideReconciliationService $reconciliation,
        private FindFiscalClientAction $findClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preflight(User $actor, TaxGuidePreflightData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        return $this->issuance->preflight(
            tenant: $tenant,
            client: $client,
            operationKey: $data->operationKey,
            competencePeriodKey: $data->competencePeriodKey,
            debitRef: $data->debitRef,
            amountCents: $data->amountCents,
            user: $actor,
        );
    }

    public function issue(User $actor, TaxGuideIssueData $data): TaxGuideIssuanceResultData
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        $result = $this->issuance->issue(
            tenant: $tenant,
            client: $client,
            operationKey: $data->operationKey,
            competencePeriodKey: $data->competencePeriodKey,
            debitRef: $data->debitRef,
            amountCents: $data->amountCents,
            dueAtIso: $data->dueAtIso,
            user: $actor,
            explicitConfirmation: $data->explicitConfirmation,
            confirmationSummary: $data->confirmationSummary,
            idempotencyKey: $data->idempotencyKey,
            correlationId: $data->correlationId,
            forceReissue: $data->forceReissue,
            operationData: $data->operationData,
        );

        return new TaxGuideIssuanceResultData(
            guide: $result['guide'],
            version: $result['version'],
            reused: (bool) $result['reused'],
            substituted: (bool) $result['substituted'],
        );
    }

    public function issueDownloadToken(User $actor, int $guideId): TaxGuideDownloadTokenData
    {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->find($tenant, $guideId);
        $version = $model->currentVersion;
        if ($version === null) {
            throw new UnprocessableEntityHttpException('Documento indisponível.');
        }

        $token = $this->downloads->issueToken($version, $actor, (int) $tenant->id);

        return new TaxGuideDownloadTokenData(
            token: (string) $token['token'],
            expiresAt: (string) $token['expires_at'],
            versionId: (int) $token['version_id'],
        );
    }

    /**
     * @return array{bytes: string, filename: string, content_type: string, sha256: string}
     */
    public function consumeDownload(string $token, ?User $actor): array
    {
        $tenant = $this->currentTenant->tenant();

        return $this->downloads->consumeToken(
            $token,
            (int) $tenant->id,
            $actor,
        );
    }

    public function confirmPayment(User $actor, int $guideId): TaxGuidePaymentResultData
    {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->find($tenant, $guideId);
        $result = $this->payments->lookupAndConfirm($tenant, $model, $actor);

        return new TaxGuidePaymentResultData(
            guide: $result['guide'],
            confirmation: $result['confirmation'],
            lookupStatus: (string) $result['status'],
        );
    }

    public function reconcile(int $guideId): TaxGuideReconcileResultData
    {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->find($tenant, $guideId);
        $version = $model->currentVersion;
        if ($version === null) {
            throw new NotFoundHttpException('Versão não encontrada.');
        }

        $result = $this->reconciliation->reconcile($tenant, $version);

        return new TaxGuideReconcileResultData(
            guide: $result['guide'],
            version: $result['version'],
            outcome: (string) $result['outcome'],
        );
    }
}
