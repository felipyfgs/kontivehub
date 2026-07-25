<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\CaixaPostalClient;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Mailbox\CaixaPostalDetailResult;
use App\DTO\Mailbox\CaixaPostalListResult;
use App\Enums\SerproCapabilityDriver;
use App\Models\Client;
use App\Models\Office;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Serpro\CapabilityDriverResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fonte Caixa Postal via executor central (operation_key tipado).
 * disabled → fail-closed; real → executor central.
 */
final class SerproCaixaPostalClient implements CaixaPostalClient
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly CapabilityDriverResolver $drivers,
        private readonly ContributorCnpjResolver $contributors,
    ) {}

    public function listMessages(array $context = []): CaixaPostalListResult
    {
        $driver = $this->drivers->forCapability('mailbox');
        if ($driver === SerproCapabilityDriver::Disabled) {
            return new CaixaPostalListResult(
                success: false,
                errorCode: 'CAPABILITY_DISABLED',
                errorMessage: 'Caixa Postal desabilitada.',
            );
        }
        $resolved = $this->resolveContext($context);
        if ($resolved instanceof CaixaPostalListResult) {
            return $resolved;
        }
        [$office, $client, $contributor] = $resolved;

        $business = array_filter([
            // Opcional no contrato. A identidade principal já vai no envelope.
            'cnpjReferencia' => $context['cnpj_referencia'] ?? null,
            // Campos obrigatórios no contrato MSGCONTRIBUINTE61.
            'statusLeitura' => (string) ($context['status_leitura'] ?? '0'),
            'indicadorPagina' => (string) ($context['indicador_pagina'] ?? '0'),
            'ponteiroPagina' => $context['ponteiro_pagina'] ?? null,
            'indicadorFavorito' => $context['indicador_favorito'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: 'caixa_postal.lista',
            businessData: $business,
            correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : null,
            module: 'mailbox',
        );
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        if (! $response->success) {
            return new CaixaPostalListResult(
                success: false,
                simulated: $response->simulated,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
            );
        }

        $dados = is_array($response->dados) ? $response->dados : [];
        $content = $this->listContent($dados);
        $items = $this->mapList($content);

        return new CaixaPostalListResult(
            success: true,
            items: $items,
            officialUnreadCount: $this->unreadCount($content, $items),
            simulated: $response->simulated,
            rawMeta: array_filter([
                'indicador_ultima_pagina' => $content['indicadorUltimaPagina'] ?? null,
                'ponteiro_pagina_retornada' => $content['ponteiroPaginaRetornada'] ?? null,
                'ponteiro_proxima_pagina' => $content['ponteiroProximaPagina'] ?? null,
                'quantidade_mensagens' => $content['quantidadeMensagens'] ?? null,
                'source_provenance' => $response->sourceProvenance,
            ], static fn ($value) => $value !== null && $value !== ''),
            sourceVersion: $response->sourceProvenance === 'SERPRO_TRIAL'
                ? 'caixa-postal-serpro-trial-v1'
                : 'caixa-postal-serpro-v1',
        );
    }

    public function getMessageDetail(string $externalMessageId, array $context = []): CaixaPostalDetailResult
    {
        $driver = $this->drivers->forCapability('mailbox');
        if ($driver === SerproCapabilityDriver::Disabled) {
            return new CaixaPostalDetailResult(
                success: false,
                externalId: $externalMessageId,
                errorCode: 'CAPABILITY_DISABLED',
                errorMessage: 'Caixa Postal desabilitada.',
            );
        }
        $resolved = $this->resolveContext($context);
        if ($resolved instanceof CaixaPostalListResult) {
            return new CaixaPostalDetailResult(
                success: false,
                externalId: $externalMessageId,
                errorCode: $resolved->errorCode,
                errorMessage: $resolved->errorMessage,
            );
        }
        [$office, $client] = $resolved;

        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: 'caixa_postal.detalhe',
            businessData: ['isn' => $externalMessageId],
            correlationId: isset($context['correlation_id']) ? (string) $context['correlation_id'] : null,
            module: 'mailbox',
        );
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        if (! $response->success) {
            return new CaixaPostalDetailResult(
                success: false,
                externalId: $externalMessageId,
                simulated: $response->simulated,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
            );
        }

        $dados = is_array($response->dados) ? $response->dados : [];
        $message = $this->detailMessage($dados, $externalMessageId);
        $body = $this->renderBody(
            isset($message['corpoModelo']) ? (string) $message['corpoModelo'] : null,
            is_array($message['variaveis'] ?? null) ? $message['variaveis'] : [],
        );

        return new CaixaPostalDetailResult(
            success: true,
            externalId: $externalMessageId,
            bodyBytes: $body,
            categoryCode: $this->nullableString($message['codigoModelo'] ?? null),
            senderCode: $this->nullableString($message['codigoSistemaRemetente'] ?? null),
            senderLabel: $this->nullableString($message['descricaoOrigem'] ?? null),
            subject: $this->renderSubject(
                $this->nullableString($message['assuntoModelo'] ?? null),
                $this->nullableString($message['valorParametroAssunto'] ?? null),
            ),
            receivedAt: $this->serproDateTime($message['dataEnvio'] ?? null, $message['horaEnvio'] ?? null),
            dueAt: $this->serproDateTime($message['dataExpiracao'] ?? $message['dataValidade'] ?? null, null),
            severityHint: (string) ($message['relevancia'] ?? '') === '2' ? 'high' : null,
            officialRead: $this->officialRead($message),
            simulated: $response->simulated,
            meta: $message,
            sourceVersion: $response->sourceProvenance === 'SERPRO_TRIAL'
                ? 'caixa-postal-serpro-trial-v1'
                : 'caixa-postal-serpro-v1',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: Office, 1: Client, 2: string}|CaixaPostalListResult
     */
    private function resolveContext(array $context): array|CaixaPostalListResult
    {
        $officeId = (int) ($context['office_id'] ?? 0);
        $clientId = (int) ($context['client_id'] ?? 0);
        $office = Office::query()->withoutGlobalScopes()->find($officeId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
        if ($office === null || $client === null) {
            return new CaixaPostalListResult(
                success: false,
                errorCode: 'CONTRIBUTOR_IDENTITY_MISSING',
                errorMessage: 'Cliente tenant-scoped não encontrado para Caixa Postal.',
            );
        }
        try {
            $contributor = $this->contributors->resolve($client);
        } catch (\Throwable) {
            return new CaixaPostalListResult(
                success: false,
                errorCode: 'CONTRIBUTOR_IDENTITY_MISSING',
                errorMessage: 'CNPJ completo do contribuinte não encontrado.',
            );
        }

        return [$office, $client, $contributor];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapList(mixed $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }
        $rows = $dados['listaMensagens'] ?? $dados['mensagens'] ?? $dados['messages'] ?? $dados;
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $externalId = $this->nullableString(
                $row['isn'] ?? $row['id'] ?? $row['idMensagem'] ?? $row['external_id'] ?? null,
            );
            if ($externalId === null) {
                continue;
            }
            $out[] = [
                'external_id' => $externalId,
                'category_code' => $this->nullableString($row['codigoModelo'] ?? null),
                'sender_code' => $this->nullableString($row['codigoSistemaRemetente'] ?? null),
                'sender_label' => $this->nullableString($row['descricaoOrigem'] ?? null),
                'subject' => $this->renderSubject(
                    $this->nullableString($row['assuntoModelo'] ?? $row['assunto'] ?? $row['subject'] ?? null),
                    $this->nullableString($row['valorParametroAssunto'] ?? null),
                ),
                'received_at' => $this->serproDateTime(
                    $row['dataEnvio'] ?? $row['dataRecebimento'] ?? null,
                    $row['horaEnvio'] ?? null,
                ) ?? ($row['received_at'] ?? null),
                'due_at' => $this->serproDateTime($row['dataValidade'] ?? null, null),
                'severity_hint' => (string) ($row['relevancia'] ?? '') === '2' ? 'high' : null,
                'official_read' => $this->officialRead($row),
                'meta' => $row,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function listContent(array $dados): array
    {
        $content = $dados['conteudo'] ?? null;
        if (! is_array($content)) {
            return $dados;
        }
        if (! array_is_list($content)) {
            return $content;
        }

        return isset($content[0]) && is_array($content[0]) ? $content[0] : [];
    }

    /** @param list<array<string, mixed>> $items */
    private function unreadCount(array $dados, array $items): ?int
    {
        if ($items === []) {
            return isset($dados['quantidadeMensagens']) ? 0 : null;
        }

        return count(array_filter(
            $items,
            static fn (array $item): bool => ($item['official_read'] ?? null) === false,
        ));
    }

    /** @return array<string, mixed> */
    private function detailMessage(array $dados, string $externalMessageId): array
    {
        $rows = $dados['conteudo'] ?? $dados['listaMensagens'] ?? null;
        if (! is_array($rows)) {
            return $dados;
        }
        if (! array_is_list($rows)) {
            return $rows;
        }
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['isn'] ?? '') === $externalMessageId) {
                return $row;
            }
        }

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
    }

    /** @param list<mixed> $variables */
    private function renderBody(?string $template, array $variables): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }
        foreach (array_values($variables) as $index => $value) {
            $template = str_replace('++'.($index + 1).'++', (string) $value, $template);
        }

        return $template;
    }

    private function renderSubject(?string $template, ?string $value): ?string
    {
        if ($template === null) {
            return null;
        }

        return $value === null ? $template : str_replace('++VARIAVEL++', $value, $template);
    }

    /** @param array<string, mixed> $message */
    private function officialRead(array $message): ?bool
    {
        if (array_key_exists('indicadorLeitura', $message)) {
            return (string) $message['indicadorLeitura'] === '1';
        }
        if (array_key_exists('lida', $message) || array_key_exists('is_read', $message)) {
            return (bool) ($message['lida'] ?? $message['is_read']);
        }
        if (! empty($message['dataLeitura'])) {
            return true;
        }

        return null;
    }

    private function serproDateTime(mixed $date, mixed $time): ?string
    {
        $date = preg_replace('/\D/', '', (string) $date) ?? '';
        if (strlen($date) !== 8) {
            return null;
        }
        $time = preg_replace('/\D/', '', (string) $time) ?? '';
        $format = strlen($time) === 6 ? '!YmdHis' : '!Ymd';
        $value = $date.(strlen($time) === 6 ? $time : '');
        $timezone = new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo'));
        $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);

        return $parsed instanceof DateTimeImmutable ? $parsed->format(DATE_ATOM) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
