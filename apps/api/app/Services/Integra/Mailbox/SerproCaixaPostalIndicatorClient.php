<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\CaixaPostalIndicatorClient;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Mailbox\CaixaPostalIndicatorResult;
use App\Enums\SerproCapabilityDriver;
use App\Models\Client;
use App\Models\Office;
use App\Services\Serpro\CapabilityDriverResolver;

final class SerproCaixaPostalIndicatorClient implements CaixaPostalIndicatorClient
{
    public const OPERATION_KEY = 'caixa_postal.indicador';

    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly CapabilityDriverResolver $drivers,
    ) {}

    public function getIndicator(array $context = []): CaixaPostalIndicatorResult
    {
        if ($this->drivers->forCapability('mailbox') === SerproCapabilityDriver::Disabled) {
            return new CaixaPostalIndicatorResult(false, errorCode: 'CAPABILITY_DISABLED', errorMessage: 'Caixa Postal desabilitada.');
        }

        $officeId = (int) ($context['office_id'] ?? 0);
        $clientId = (int) ($context['client_id'] ?? 0);
        $office = Office::query()->withoutGlobalScopes()->find($officeId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
        if ($office === null || $client === null) {
            return new CaixaPostalIndicatorResult(false, errorCode: 'CONTRIBUTOR_IDENTITY_MISSING', errorMessage: 'Cliente tenant-scoped não encontrado.');
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
            return new CaixaPostalIndicatorResult(
                false,
                simulated: $response->simulated,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
            );
        }

        $dados = is_array($response->dados) ? $response->dados : [];
        $indicator = filter_var($dados['indicadorMensagensNovas'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if ($indicator === null || ! in_array($indicator, [0, 1, 2], true)) {
            return new CaixaPostalIndicatorResult(false, errorCode: 'MAILBOX_INDICATOR_INVALID', errorMessage: 'Indicador de mensagens novas inválido.');
        }

        return new CaixaPostalIndicatorResult(true, $indicator, $response->simulated, meta: $dados);
    }
}
