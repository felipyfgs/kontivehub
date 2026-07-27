<?php

namespace App\Services\MeiAutomation;

use App\Contracts\FiscalMutationTransport;
use App\Contracts\SerproFiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Exceptions\MeiAutomationTransportException;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\Tenant;
use App\Services\Fiscal\Mutations\FiscalMutationIntegraRequestFactory;

final class MeiPortalFiscalMutationTransport implements FiscalMutationTransport
{
    public function __construct(
        private readonly SerproFiscalMutationTransport $serpro,
        private readonly MeiProviderPolicy $policy,
        private readonly MeiAutomationAttemptService $attemptService,
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly MeiAutomationClient $client,
        private readonly MeiAutomationSyncService $sync,
        private readonly FiscalMutationIntegraRequestFactory $requests,
    ) {}

    public function execute(IntegraRequest $request): IntegraResponse
    {
        if (! $this->isMeiDas($request)) {
            return $this->serpro->execute($request);
        }

        $tenant = Tenant::query()->findOrFail($request->tenantId);
        $operationKey = $this->operationKey($request);
        $providers = $this->policy->providers($tenant, $operationKey);
        if (($providers[0] ?? MeiProvider::Serpro) === MeiProvider::Serpro) {
            return $this->serpro->execute($request);
        }

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($request->clientId)
            ->firstOrFail();
        $mutation = $this->mutation($request);
        $input = [
            'cnpj' => $request->contributorCnpj,
            'competencies' => array_values((array) ($request->businessData['competencies'] ?? [])),
        ];
        $dueDate = $request->businessData['due_date'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            $input['due_date'] = $dueDate;
        }
        $provider = (bool) config('mei_automation.fixture_enabled', false)
            && ! (bool) config('mei_automation.live_egress_enabled', false)
            ? MeiProvider::Fixture
            : MeiProvider::ReceitaPortal;
        $attempt = $this->attemptService->start(
            tenant: $tenant,
            client: $client,
            operationKey: $operationKey,
            provider: $provider,
            idempotencyKey: 'mutation:'.hash(
                'sha256',
                (string) $request->idempotencyKey.'|'.$operationKey,
            ),
            input: $input,
            mutation: $mutation,
        );

        if ($attempt->external_job_id === null) {
            try {
                $job = $this->client->create($this->attemptService->jobRequest($attempt, $input));
                $attempt = $this->attempts->synchronize($attempt, $job);
            } catch (MeiAutomationTransportException) {
                if ($this->hasSerproFallback($providers)) {
                    $this->attempts->markFallback($attempt, 'PORTAL_UNAVAILABLE');

                    return $this->serpro->execute($this->requests->makeForSerpro($mutation));
                }

                return $this->failure('PORTAL_UNAVAILABLE', 'Sidecar MEI indisponível antes da submissão.');
            }
        }

        if ($attempt->status->shouldPoll()) {
            $this->sync->schedule($attempt);

            return $this->processing($attempt);
        }

        return $this->terminal($attempt, $providers);
    }

    public function reconcile(IntegraRequest $request): IntegraResponse
    {
        if (! $this->isMeiDas($request)) {
            return $this->serpro->reconcile($request);
        }

        $mutation = $this->mutation($request);
        $attempt = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $request->tenantId)
            ->where('fiscal_mutation_operation_id', $mutation->id)
            ->latest('id')
            ->first();
        if ($attempt === null) {
            return $this->serpro->reconcile($this->requests->makeForSerpro($mutation));
        }
        if ($attempt->status === MeiAutomationStatus::Succeeded) {
            return $this->success($attempt);
        }
        if ($attempt->status->shouldPoll()) {
            return $this->processing($attempt);
        }
        if ($attempt->submitted_at !== null || $attempt->status === MeiAutomationStatus::Uncertain) {
            return $this->uncertain($attempt);
        }

        return $this->failure(
            (string) ($attempt->error_code ?: 'PORTAL_FAILED'),
            'O portal não concluiu a emissão.',
        );
    }

    /** @param list<MeiProvider> $providers */
    private function terminal(
        MeiAutomationAttempt $attempt,
        array $providers,
    ): IntegraResponse {
        if ($attempt->status === MeiAutomationStatus::Succeeded) {
            $attempt = $this->sync->synchronize($attempt);

            return $attempt->status === MeiAutomationStatus::Succeeded
                ? $this->success($attempt)
                : $this->uncertain($attempt);
        }
        if ($attempt->submitted_at !== null || $attempt->status === MeiAutomationStatus::Uncertain) {
            return $this->uncertain($attempt);
        }
        if ($this->hasSerproFallback($providers)) {
            $this->attempts->markFallback($attempt, (string) ($attempt->error_code ?: 'PORTAL_UNAVAILABLE'));

            return $this->serpro->execute($this->requests->makeForSerpro($attempt->mutationOperation()->firstOrFail()));
        }

        return $this->failure(
            (string) ($attempt->error_code ?: 'PORTAL_FAILED'),
            'O portal não concluiu a emissão.',
        );
    }

    private function processing(MeiAutomationAttempt $attempt): IntegraResponse
    {
        return new IntegraResponse(
            success: true,
            httpStatus: 202,
            body: ['status' => 'PROCESSING', 'attempt_id' => (int) $attempt->id],
            retryAfterSeconds: $this->sync->pollIntervalSeconds(),
            correlationId: (string) $attempt->external_job_id,
            businessStatus: 'PROCESSING',
            operationKey: (string) $attempt->operation_key,
            sourceProvenance: FiscalSourceProvenance::ReceitaPortal->value,
        );
    }

    private function success(MeiAutomationAttempt $attempt): IntegraResponse
    {
        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['status' => 'CONFIRMED', 'attempt_id' => (int) $attempt->id],
            correlationId: (string) $attempt->external_job_id,
            businessStatus: 'CONFIRMED',
            operationKey: (string) $attempt->operation_key,
            sourceProvenance: FiscalSourceProvenance::ReceitaPortal->value,
        );
    }

    private function uncertain(MeiAutomationAttempt $attempt): IntegraResponse
    {
        return new IntegraResponse(
            success: false,
            httpStatus: 504,
            body: ['status' => 'UNKNOWN', 'attempt_id' => (int) $attempt->id],
            errorCode: 'GATEWAY_TIMEOUT',
            errorMessage: 'Resultado portal incerto após possível submissão.',
            correlationId: (string) $attempt->external_job_id,
            businessStatus: 'UNKNOWN',
            operationKey: (string) $attempt->operation_key,
            sourceProvenance: FiscalSourceProvenance::ReceitaPortal->value,
        );
    }

    private function failure(string $code, string $message): IntegraResponse
    {
        return new IntegraResponse(
            success: false,
            httpStatus: 503,
            body: ['status' => 'FAILED'],
            errorCode: $code,
            errorMessage: $message,
            sourceProvenance: FiscalSourceProvenance::ReceitaPortal->value,
        );
    }

    private function mutation(IntegraRequest $request): FiscalMutationOperation
    {
        $id = $request->mutationOperationId;
        if ($id === null) {
            throw new \RuntimeException('Mutação fiscal canônica ausente no pedido Integra.');
        }

        return FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $request->tenantId)
            ->where('client_id', $request->clientId)
            ->findOrFail($id);
    }

    private function operationKey(IntegraRequest $request): string
    {
        return strtoupper((string) ($request->businessData['output_format'] ?? 'PDF')) === 'BARCODE'
            ? 'pgmei.gerardascodbarra'
            : 'pgmei.gerardaspdf';
    }

    private function isMeiDas(IntegraRequest $request): bool
    {
        return in_array(strtolower($request->operationKey), [
            'pgmei.gerardaspdf',
            'pgmei.gerardascodbarra',
        ], true);
    }

    /** @param list<MeiProvider> $providers */
    private function hasSerproFallback(array $providers): bool
    {
        return in_array(MeiProvider::Serpro, array_slice($providers, 1), true);
    }
}
