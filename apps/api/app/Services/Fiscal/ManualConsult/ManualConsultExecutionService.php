<?php

namespace App\Services\Fiscal\ManualConsult;

use App\DTO\Integra\MitListaApuracoesRequest;
use App\Enums\ManualConsultEligibility;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Jobs\Fiscal\RefreshRegistrationLinksJob;
use App\Jobs\Fiscal\RefreshTaxProcessesJob;
use App\Models\Client;
use App\Models\Office;
use App\Services\Fiscal\Guides\PagtowebPaymentCountQueryService;
use App\Services\Fiscal\Guides\PagtowebPaymentListQueryService;
use App\Services\Fiscal\Guides\SicalcRevenueSupportQueryService;
use App\Services\Fiscal\SimplesMei\CcmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\CcmeiRegistrationStatusQueryService;
use App\Services\Fiscal\SimplesMei\DefisDeclarationsMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\DefisLatestDeclarationMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\DefisSpecificDeclarationMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\SimplesMeiQueryService;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\MitApuracaoService;
use App\Services\Integra\Dctfweb\MitListaApuracoesQueryService;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Services\Integra\Sitfis\SitfisSnapshotService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Façade de execução de consulta manual confirmada — despacha adapters existentes.
 * Nunca monta envelope genérico a partir do payload do cliente.
 */
final class ManualConsultExecutionService
{
    public function __construct(
        private readonly ManualConsultEligibilityGate $eligibility,
        private readonly ManualConsultReadPolicy $readPolicy,
        private readonly ManualConsultExecutionContext $executionContext,
        private readonly FiscalMonitoringRunService $runs,
        private readonly CcmeiMonitoringQueryService $ccmei,
        private readonly CcmeiRegistrationStatusQueryService $ccmeiStatus,
        private readonly DefisDeclarationsMonitoringQueryService $defisList,
        private readonly DefisLatestDeclarationMonitoringQueryService $defisLatest,
        private readonly DefisSpecificDeclarationMonitoringQueryService $defisSpecific,
        private readonly PgmeiMonitoringQueryService $pgmei,
        private readonly PgdasdMonitoringQueryService $pgdasd,
        private readonly SimplesMeiQueryService $simplesMei,
        private readonly SicalcRevenueSupportQueryService $sicalc,
        private readonly PagtowebPaymentListQueryService $pagtowebList,
        private readonly PagtowebPaymentCountQueryService $pagtowebCount,
        private readonly SitfisSnapshotService $sitfis,
        private readonly DctfwebDeclarationService $dctfwebDeclarations,
        private readonly MitApuracaoService $mit,
        private readonly MitListaApuracoesQueryService $mitLista,
        private readonly EnsureClientProcuracaoForConsult $procuracaoEnsure,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(
        Office $office,
        Client $client,
        string $actionId,
        array $params,
        bool $confirmed,
        ?int $actorUserId,
    ): array {
        if (! $confirmed) {
            throw new HttpException(422, 'Consulta manual exige confirmed=true.');
        }

        $def = $this->readPolicy->authorizeDispatch(
            $office,
            $client,
            $actionId,
            $actorUserId,
        );

        if ($def->requiredProxyPowers !== []) {
            $ensure = $this->procuracaoEnsure->ensure(
                $office,
                $client,
                $this->eligibility->environment(),
                $def->requiredProxyPowers,
                $actorUserId,
            );
            if (! $ensure['ok']) {
                throw new HttpException(
                    422,
                    $ensure['message'] ?? ('Elegibilidade Integra negada: '.($ensure['code'] ?? 'PROXY_POWER_MISSING')),
                );
            }
        }

        $this->readPolicy->assertDispatchReady($office, $client, $def, $actorUserId);

        $result = $this->executionContext->within(
            $def,
            fn (): array => $this->dispatch($office, $client, $def, $params, $actorUserId),
        );

        return [
            'action_id' => $def->actionId,
            'eligibility' => ManualConsultEligibility::Ready->value,
            'async' => $def->async,
            'module_route' => $def->moduleRoute,
            'result' => $this->sanitizeResult($result),
            'serpro_call' => $def->async ? 'QUEUED' : 'ENQUEUED',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dispatch(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        array $params,
        ?int $actorUserId,
    ): array {
        return match ($def->handler) {
            'ccmei_data' => $this->ccmei->enqueueManualConsult($office, $client, $actorUserId),
            'ccmei_status' => $this->ccmeiStatus->enqueueManualConsult($office, $client, $actorUserId),
            'defis_list' => $this->defisList->enqueueManualConsult($office, $client, $actorUserId),
            'defis_latest' => $this->defisLatest->enqueueManualConsult(
                $office,
                $client,
                (int) $this->requireParam($params, 'year'),
                $actorUserId,
            ),
            'defis_specific' => $this->defisSpecific->enqueueManualConsult(
                $office,
                $client,
                (int) $this->requireParam($params, 'reference_id'),
                $actorUserId,
            ),
            'pgmei_debt' => [
                'runs' => $this->pgmei->enqueueManualConsult(
                    $office,
                    [$client->id],
                    (int) $this->requireParam($params, 'year'),
                    true,
                    $actorUserId,
                ),
            ],
            'pgdasd_documents' => $this->pgdasdDocuments($office, $client, $def, $params, $actorUserId),
            'pgdasd_extract' => $this->enqueueRun(
                $office,
                $client,
                $def,
                $actorUserId,
                progress: [
                    'numero_das' => (string) $this->requireParam($params, 'numero_das'),
                ],
            ),
            'regime_calendar' => $this->simplesMei->enqueueConsult(
                office: $office,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR_ANOS_CALENDARIOS',
                actorId: $actorUserId,
                dispatch: true,
            )->toPublicArray(),
            'regime_option' => $this->simplesMei->enqueueConsult(
                office: $office,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR',
                periodKey: (string) $this->requireParam($params, 'year'),
                actorId: $actorUserId,
                dispatch: true,
            )->toPublicArray(),
            'regime_resolution' => $this->simplesMei->enqueueConsult(
                office: $office,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR_RESOLUCAO',
                periodKey: (string) $this->requireParam($params, 'year'),
                actorId: $actorUserId,
                dispatch: true,
            )->toPublicArray(),
            'sicalc_support' => $this->sicalc->enqueueManualConsult(
                $office,
                $client,
                (string) $this->requireParam($params, 'codigo_receita'),
                $actorUserId,
            ),
            'pagtoweb_list' => $this->pagtowebList->enqueueManualConsult(
                $office,
                $client,
                (array) ($params['filters'] ?? []),
                $actorUserId,
            ),
            'pagtoweb_count' => $this->pagtowebCount->enqueueManualConsult(
                $office,
                $client,
                (array) ($params['filters'] ?? []),
                $actorUserId,
            ),
            'sitfis_refresh' => $this->sitfisRefresh($office, $client, $actorUserId),
            'dctfweb_read' => $this->dctfwebRead($office, $client, $def, $params, $actorUserId),
            'mit_read' => $this->mitRead($office, $client, $def, $params, $actorUserId),
            'mit_lista' => $this->mitLista($office, $client, $params, $actorUserId),
            'mailbox_list', 'mailbox_indicator', 'dte_status' => $this->enqueueRun(
                $office,
                $client,
                $def,
                $actorUserId,
            ),
            'mailbox_detail' => $this->enqueueRun(
                $office,
                $client,
                $def,
                $actorUserId,
                progress: ['message_id' => (string) $this->requireParam($params, 'message_id')],
            ),
            'installment_read' => $this->installmentRead($office, $client, $def, $params, $actorUserId),
            'registrations_refresh' => $this->registrationsRefresh($office, $client, $def, $actorUserId),
            'tax_process_refresh' => $this->taxProcessRefresh($office, $client, $def, $actorUserId),
            default => throw new HttpException(422, ManualConsultEligibility::AdapterMissing->label()),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function pgdasdDocuments(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        array $params,
        ?int $actorUserId,
    ): array {
        // Serviço 13 (lista declarações) usa run genérico; 14/15 usam coleta documental.
        if ($def->operationKey === 'pgdasd.consdeclaracao') {
            $year = isset($params['year']) ? trim((string) $params['year']) : null;
            $periodKey = isset($params['period_key']) ? trim((string) $params['period_key']) : null;
            if (($year !== null && $year !== '') === ($periodKey !== null && $periodKey !== '')) {
                throw ValidationException::withMessages([
                    'params' => ['Informe exatamente um entre ano-calendário e competência.'],
                ]);
            }

            return $this->enqueueRun(
                $office,
                $client,
                $def,
                $actorUserId,
                periodKey: $periodKey,
                progress: $year !== null && $year !== '' ? ['ano_calendario' => $year] : [],
            );
        }

        $periodKey = (string) $this->requireParam($params, 'period_key');
        $declarationNumber = trim((string) ($params['declaration_number'] ?? ''));
        $operation = $declarationNumber !== '' || $def->operationKey === 'pgdasd.consdecrec'
            ? 'CONSULTAR_RECIBO'
            : 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO';
        $payload = [
            'period_key' => $periodKey,
            'periodoApuracao' => str_replace('-', '', $periodKey),
        ];
        if ($declarationNumber !== '') {
            $payload['numeroDeclaracao'] = $declarationNumber;
        }

        $run = $this->pgdasd->enqueueDocumentCollect(
            $office,
            $client,
            $operation,
            $payload,
            $actorUserId,
        );

        return $run->toPublicArray();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dctfwebRead(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        array $params,
        ?int $actorUserId,
    ): array {
        $periodKey = (string) $this->requireParam($params, 'period_key');
        $declaration = $this->dctfwebDeclarations->findOrCreate($office, $client, $periodKey);
        $op = $def->runCodes['operation'] ?? DctfwebCodes::OP_CONSULTAR_RECIBO;
        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: DctfwebCodes::SYSTEM_DCTFWEB,
            serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
            operationCode: $op,
            competence: $declaration->competence,
            actorId: $actorUserId,
            correlationId: sprintf('manual-dctfweb-%d-%s', $client->id, (string) Str::uuid()),
            dispatch: false,
        );

        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['period_key'] = $periodKey;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function mitRead(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        array $params,
        ?int $actorUserId,
    ): array {
        $periodKey = (string) $this->requireParam($params, 'period_key');
        $apuracao = $this->mit->findOrCreate($office, $client, $periodKey);
        $op = $def->runCodes['operation'] ?? 'CONSULTAR_SITUACAO';
        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: DctfwebCodes::SYSTEM_MIT,
            serviceCode: DctfwebCodes::SERVICE_MIT,
            operationCode: $op,
            competence: $apuracao->competence,
            actorId: $actorUserId,
            correlationId: sprintf('manual-mit-%d-%s', $client->id, (string) Str::uuid()),
            dispatch: false,
        );

        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['period_key'] = $periodKey;
        if ($def->operationKey === 'mit.consapuracao') {
            $progress['idApuracao'] = (int) $this->requireParam($params, 'id_apuracao');
        }
        if ($def->operationKey === 'mit.situacaoenc') {
            $progress['protocoloEncerramento'] = (string) $this->requireParam($params, 'protocolo_encerramento');
        }
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function mitLista(
        Office $office,
        Client $client,
        array $params,
        ?int $actorUserId,
    ): array {
        $filters = MitListaApuracoesRequest::fromArray(array_filter([
            'anoApuracao' => isset($params['anoApuracao']) ? (int) $params['anoApuracao'] : null,
            'mesApuracao' => isset($params['mesApuracao']) ? (int) $params['mesApuracao'] : null,
            'situacaoApuracao' => isset($params['situacaoApuracao']) ? (int) $params['situacaoApuracao'] : null,
        ], static fn (?int $v): bool => $v !== null));

        $run = $this->mitLista->enqueue(
            office: $office,
            client: $client,
            filters: $filters,
            actorId: $actorUserId,
            correlationId: null,
        );

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : ['id' => $run->id ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function sitfisRefresh(Office $office, Client $client, ?int $actorUserId): array
    {
        $result = $this->sitfis->refresh(
            office: $office,
            client: $client,
            force: false,
            actorId: $actorUserId,
            dispatch: true,
        );

        return [
            'enqueued' => $result['enqueued'] ?? false,
            'reused_snapshot' => $result['reused_snapshot'] ?? false,
            'reason' => $result['reason'] ?? null,
            'run' => isset($result['run']) && $result['run'] !== null
                ? $result['run']->toPublicArray()
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function installmentRead(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        array $params,
        ?int $actorUserId,
    ): array {
        $modality = $this->resolveInstallmentModality($def->operationKey, $params);
        // Códigos canônicos do hub (ParcelamentoReadAdapter), não idServico SERPRO bruto.
        $operation = match (true) {
            str_ends_with($def->operationKey, '.pedidosparc') => 'CONSULTAR_PEDIDOS',
            str_ends_with($def->operationKey, '.obterparc') => 'CONSULTAR_PARCELAMENTO',
            str_ends_with($def->operationKey, '.parcelasparagerar') => 'CONSULTAR_PARCELAS',
            str_ends_with($def->operationKey, '.detpagtoparc') => 'CONSULTAR_PAGAMENTO',
            default => 'MONITOR',
        };

        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: ParcelamentoServiceCatalog::SOLUTION,
            serviceCode: $modality,
            operationCode: $operation,
            actorId: $actorUserId,
            correlationId: sprintf('manual-parc-%d-%s', $client->id, (string) Str::uuid()),
            dispatch: true,
        );

        return $run->toPublicArray();
    }

    /**
     * Modalidade deriva do prefixo de operation_key; params.modality, se enviado, deve bater.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveInstallmentModality(string $operationKey, array $params): string
    {
        $prefix = strtolower((string) (explode('.', $operationKey, 2)[0] ?? ''));
        $derived = strtoupper(str_replace('_', '-', $prefix));
        if (ParcelamentoServiceCatalog::parseModality($derived) === null) {
            throw new HttpException(422, 'Modalidade de parcelamento inválida para a ação.');
        }

        if (isset($params['modality']) && trim((string) $params['modality']) !== '') {
            $supplied = strtoupper(trim((string) $params['modality']));
            if ($supplied !== $derived) {
                throw new HttpException(
                    422,
                    "Modalidade {$supplied} não corresponde à ação ({$derived}).",
                );
            }
        }

        return $derived;
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationsRefresh(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        ?int $actorUserId,
    ): array {
        $job = RefreshRegistrationLinksJob::dispatchIfAllowed(
            (int) $office->id,
            (int) $client->id,
            bin2hex(random_bytes(8)),
            manualActionId: $def->actionId,
            actorUserId: $actorUserId,
        );
        if ($job === null) {
            throw new HttpException(423, 'Capability registrations desabilitada ou kill switch ativo.');
        }

        return ['queued' => true, 'client_id' => $client->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxProcessRefresh(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        ?int $actorUserId,
    ): array {
        $job = RefreshTaxProcessesJob::dispatchIfAllowed(
            (int) $office->id,
            (int) $client->id,
            bin2hex(random_bytes(8)),
            manualActionId: $def->actionId,
            actorUserId: $actorUserId,
        );
        if ($job === null) {
            throw new HttpException(423, 'Capability tax_processes desabilitada ou kill switch ativo.');
        }

        return ['queued' => true, 'client_id' => $client->id];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function enqueueRun(
        Office $office,
        Client $client,
        ManualConsultActionDefinition $def,
        ?int $actorUserId,
        ?string $periodKey = null,
        array $progress = [],
    ): array {
        $codes = $def->runCodes;
        if ($codes === null) {
            throw new HttpException(422, ManualConsultEligibility::AdapterMissing->label());
        }

        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: $codes['system'],
            serviceCode: $codes['service'],
            operationCode: $codes['operation'],
            competence: null,
            actorId: $actorUserId,
            correlationId: sprintf('manual-%s-%d-%s', $def->handler, $client->id, (string) Str::uuid()),
            dispatch: false,
        );

        if ($periodKey !== null || $progress !== []) {
            $p = is_array($run->progress) ? $run->progress : [];
            if ($periodKey !== null) {
                $p['period_key'] = $periodKey;
            }
            foreach ($progress as $k => $v) {
                $p[$k] = $v;
            }
            $run->forceFill(['progress' => $p])->save();
        }

        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->toPublicArray();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function requireParam(array $params, string $name): mixed
    {
        if (! array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
            throw ValidationException::withMessages([
                "params.{$name}" => ["Parâmetro obrigatório: {$name}."],
            ]);
        }

        return $params[$name];
    }

    /**
     * @param  array<string, mixed>|object  $result
     * @return array<string, mixed>
     */
    private function sanitizeResult(array|object $result): array
    {
        if (is_object($result)) {
            if (method_exists($result, 'toPublicArray')) {
                $result = $result->toPublicArray();
            } else {
                $result = (array) $result;
            }
        }

        $blocked = [
            'autenticar_procurador_token',
            'procurador_token',
            'consumer_secret',
            'pfx',
            'pem',
            'termo_xml',
            'canonical_xml',
            'vault_path',
            'vault_object_id',
        ];

        $out = [];
        foreach ($result as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $b) {
                if (str_contains($lower, $b)) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$key] = $this->sanitizeResult($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
