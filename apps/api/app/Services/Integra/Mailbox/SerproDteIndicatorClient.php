<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\DteIndicatorClient;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Mailbox\DteIndicatorResult;
use App\Enums\MailboxDteStatus;
use App\Enums\SerproCapabilityDriver;
use App\Models\Client;
use App\Models\Office;
use App\Services\Serpro\CapabilityDriverResolver;

final class SerproDteIndicatorClient implements DteIndicatorClient
{
    public const OPERATION_KEY = 'dte.consultar';

    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly CapabilityDriverResolver $drivers,
    ) {}

    public function getIndicator(array $context = []): DteIndicatorResult
    {
        $driver = $this->drivers->forCapability('mailbox');
        if ($driver === SerproCapabilityDriver::Disabled) {
            return new DteIndicatorResult(
                success: false,
                status: MailboxDteStatus::Unknown,
                errorCode: 'CAPABILITY_DISABLED',
                errorMessage: 'DTE desabilitado.',
            );
        }
        $officeId = (int) ($context['office_id'] ?? 0);
        $clientId = (int) ($context['client_id'] ?? 0);
        $office = Office::query()->withoutGlobalScopes()->find($officeId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
        if ($office === null || $client === null) {
            return new DteIndicatorResult(
                success: false,
                status: MailboxDteStatus::Unknown,
                errorCode: 'CONTRIBUTOR_IDENTITY_MISSING',
                errorMessage: 'Cliente tenant-scoped não encontrado para DTE.',
            );
        }

        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: self::OPERATION_KEY,
            businessData: [],
            correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : null,
            module: 'mailbox',
        );
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        if (! $response->success) {
            return new DteIndicatorResult(
                success: false,
                status: MailboxDteStatus::Unknown,
                simulated: $response->simulated,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
            );
        }

        $dados = is_array($response->dados) ? $response->dados : [];
        $active = (bool) ($dados['indicador'] ?? $dados['ativo'] ?? $dados['active'] ?? false);

        return new DteIndicatorResult(
            success: true,
            status: $active ? MailboxDteStatus::Active : MailboxDteStatus::Inactive,
            simulated: $response->simulated,
            meta: $dados,
        );
    }
}
