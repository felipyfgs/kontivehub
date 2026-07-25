<?php

namespace App\Services\Integra\Registrations;

use App\Contracts\SerproOperationExecutor;
use App\Models\Client;
use App\Models\Office;
use RuntimeException;

/** Execuções manuais, tenant-scoped e não mutantes do ciclo PNR de renúncia. */
final class PnrRenunciationReadService
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly PnrRenunciationProjectionService $projections,
    ) {}

    /** @param array{dt_inicio?: string|null, dt_fim?: string|null, page?: int|null, page_size?: int|null} $filters */
    public function history(Office $office, Client $client, array $filters, ?string $correlationId = null): array
    {
        $this->assertClient($office, $client);
        $businessData = $this->historyBusinessData($filters);
        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: PnrRenunciationProjectionService::HISTORY_OPERATION_KEY,
            businessData: $businessData,
            idempotencyKey: $this->idempotencyKey('history', $office, $client, $businessData),
            correlationId: $correlationId,
        );

        if (! $response->success) {
            return $this->failure($response->errorCode, $response->errorMessage);
        }
        if ($response->hasSimulatedSource()) {
            return $this->failure('SIMULATED_SOURCE_REJECTED', 'Fonte sintética não pode gerar consulta PNR.');
        }

        try {
            $rows = $this->projections->projectHistory($office, $client, (string) $response->sourceProvenance, $response->dados);
        } catch (RuntimeException $exception) {
            return $this->failure('RESPONSE_LAYOUT_INVALID', $exception->getMessage());
        }

        return ['success' => true, 'count' => count($rows)];
    }

    public function status(Office $office, Client $client, string $requestId, ?string $correlationId = null): array
    {
        $this->assertClient($office, $client);
        $requestId = trim($requestId);
        if ($requestId === '') {
            throw new RuntimeException('Identificador da solicitação é obrigatório.');
        }
        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: PnrRenunciationProjectionService::STATUS_OPERATION_KEY,
            businessData: ['idSolicitacao' => $requestId],
            idempotencyKey: $this->idempotencyKey('status', $office, $client, ['idSolicitacao' => $requestId]),
            correlationId: $correlationId,
        );

        if (! $response->success) {
            return $this->failure($response->errorCode, $response->errorMessage);
        }
        if ($response->hasSimulatedSource()) {
            return $this->failure('SIMULATED_SOURCE_REJECTED', 'Fonte sintética não pode gerar consulta PNR.');
        }

        try {
            $projection = $this->projections->projectStatus($office, $client, (string) $response->sourceProvenance, $response->dados);
        } catch (RuntimeException $exception) {
            return $this->failure('RESPONSE_LAYOUT_INVALID', $exception->getMessage());
        }

        return ['success' => true, 'renunciation_id' => $projection?->renunciation_id];
    }

    public function receipt(Office $office, Client $client, int $renunciationId, ?string $correlationId = null): array
    {
        $this->assertClient($office, $client);
        if ($renunciationId < 1) {
            throw new RuntimeException('Identificador da renúncia deve ser positivo.');
        }
        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: PnrRenunciationProjectionService::RECEIPT_OPERATION_KEY,
            businessData: ['idRenuncia' => $renunciationId],
            idempotencyKey: $this->idempotencyKey('receipt', $office, $client, ['idRenuncia' => $renunciationId]),
            correlationId: $correlationId,
        );

        if (! $response->success) {
            return $this->failure($response->errorCode, $response->errorMessage);
        }
        if ($response->hasSimulatedSource()) {
            return $this->failure('SIMULATED_SOURCE_REJECTED', 'Fonte sintética não pode gerar comprovante PNR.');
        }

        try {
            $projection = $this->projections->projectReceipt($office, $client, $renunciationId, (string) $response->sourceProvenance, $response->dados);
        } catch (RuntimeException $exception) {
            return $this->failure('RESPONSE_LAYOUT_INVALID', $exception->getMessage());
        }

        return ['success' => true, 'renunciation_id' => $projection->renunciation_id];
    }

    /** @param array{dt_inicio?: string|null, dt_fim?: string|null, page?: int|null, page_size?: int|null} $filters @return array<string, int|string> */
    private function historyBusinessData(array $filters): array
    {
        $start = isset($filters['dt_inicio']) ? trim((string) $filters['dt_inicio']) : '';
        $end = isset($filters['dt_fim']) ? trim((string) $filters['dt_fim']) : '';
        if (($start === '') !== ($end === '')) {
            throw new RuntimeException('Informe as duas datas do período ou deixe ambas vazias.');
        }
        if ($start !== '' && (! $this->isDate($start) || ! $this->isDate($end) || $start > $end)) {
            throw new RuntimeException('Período de renúncias inválido.');
        }

        $page = max(0, (int) ($filters['page'] ?? 0));
        $size = min(50, max(1, (int) ($filters['page_size'] ?? 10)));

        return array_filter([
            'dtInicio' => $start !== '' ? $start : null,
            'dtFim' => $end !== '' ? $end : null,
            'page' => $page,
            'pageSize' => $size,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, int|string> $context */
    private function idempotencyKey(string $action, Office $office, Client $client, array $context): string
    {
        return sprintf('pnr:%s:%d:%d:%s', $action, $office->id, $client->id, substr(hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)), 0, 20));
    }

    /** @return array{success: false, error_code: string|null, error_message: string|null} */
    private function failure(?string $code, ?string $message): array
    {
        return ['success' => false, 'error_code' => $code, 'error_message' => $message];
    }
}
