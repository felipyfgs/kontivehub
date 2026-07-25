<?php

namespace App\Services\Integra;

use App\Contracts\IntegraContadorClient;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;

/** Transporte local determinístico; lê apenas fixtures versionadas e nunca abre rede. */
final class FixtureIntegraContadorClient implements IntegraContadorClient
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $fixtures = null;

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $fixture = $this->fixtures()[strtolower($request->operationKey)] ?? null;
        if ($fixture === null) {
            return new IntegraResponse(
                success: false,
                httpStatus: 501,
                body: [],
                errorCode: 'FIXTURE_NOT_AVAILABLE',
                errorMessage: 'Cenário sintético ainda não disponível para esta operação.',
                correlationId: $request->correlationId,
                operationKey: $request->operationKey,
                requestTag: $request->resolvedRequestTag(),
                sourceProvenance: FiscalSourceProvenance::Fixture->value,
            );
        }

        $response = is_array($fixture['response'] ?? null) ? $fixture['response'] : [];
        $status = (int) ($response['status'] ?? 200);
        $messages = is_array($response['mensagens'] ?? null) ? $response['mensagens'] : [];
        $data = $response['dados'] ?? null;

        return new IntegraResponse(
            success: $status >= 200 && $status < 300,
            httpStatus: $status,
            body: $response,
            simulated: false,
            correlationId: $request->correlationId,
            mensagens: $messages,
            dados: $data,
            operationKey: $request->operationKey,
            requestTag: $request->resolvedRequestTag(),
            functionalRoute: isset($fixture['route']) ? (string) $fixture['route'] : null,
            sourceProvenance: FiscalSourceProvenance::Fixture->value,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function fixtures(): array
    {
        if ($this->fixtures !== null) {
            return $this->fixtures;
        }

        $raw = file_get_contents(base_path('resources/serpro/contract-fixtures.v2026-07-16.json'));
        $decoded = $raw === false ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $indexed = [];
        foreach ((array) ($decoded['fixtures'] ?? []) as $fixture) {
            if (is_array($fixture) && is_string($fixture['operation_key'] ?? null)) {
                $indexed[strtolower($fixture['operation_key'])] = $fixture;
            }
        }

        return $this->fixtures = $indexed;
    }
}
