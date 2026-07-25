<?php

namespace App\Services\Adn;

use App\Contracts\AdnContributorClient;
use App\Domain\Adn\DistributionDocumentDto;
use App\Domain\Adn\DistributionPageDto;
use App\Domain\Adn\EventsPageDto;
use App\Enums\AdnDocumentType;
use App\Exceptions\Adn\AdnInvalidResponseException;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;

/**
 * Cliente HTTP da API de Contribuintes do ADN (distribuição por NSU).
 *
 * Envelope real: JSON com StatusProcessamento + LoteDFe (não retDistDFeInt/XML).
 * Transporte mTLS próprio — não usar SDKs comunitários ADN (PEM em disco / TLS off).
 *
 * @see docs/adr/001-adn-api-client.md
 */
final class HttpAdnContributorClient implements AdnContributorClient
{
    public const STATUS_DOCUMENTS_FOUND = 'DOCUMENTOS_LOCALIZADOS';

    public const STATUS_NONE_FOUND = 'NENHUM_DOCUMENTO_LOCALIZADO';

    public const STATUS_REJECTION = 'REJEICAO';

    public function __construct(
        private readonly CurlMtlsTransport $transport,
        private readonly string $baseUrl,
    ) {}

    public function distribution(array $certificate, string $cnpjConsulta, int $lastNsu, bool $lote = true): DistributionPageDto
    {
        $nsu = max(0, $lastNsu);
        $query = http_build_query([
            'cnpjConsulta' => $cnpjConsulta,
            'lote' => $lote ? 'true' : 'false',
        ]);
        $url = rtrim($this->baseUrl, '/').'/DFe/'.$nsu.($query ? '?'.$query : '');

        $response = $this->transport->get($url, $certificate);
        // ADN devolve HTTP 404 com JSON NENHUM_DOCUMENTO_LOCALIZADO (fim da distribuição).
        $this->assertDistributionHttpOk($response['status'], $response['body']);

        return $this->parseDistribution($response['body'], $nsu);
    }

    public function events(array $certificate, string $accessKey): EventsPageDto
    {
        // Manual oficial: GET /NFSe/{ChaveAcesso}/Eventos (não /DFe/.../Eventos).
        $url = rtrim($this->baseUrl, '/').'/NFSe/'.rawurlencode($accessKey).'/Eventos';
        $response = $this->transport->get($url, $certificate);
        $this->assertDistributionHttpOk($response['status'], $response['body']);

        $payload = $this->decodeJsonObject($response['body']);
        $status = $this->stringField($payload, 'StatusProcessamento');

        if ($status === self::STATUS_REJECTION) {
            throw new AdnPermanentException($this->sanitizedRejectionMessage($payload));
        }

        if (! in_array($status, [self::STATUS_DOCUMENTS_FOUND, self::STATUS_NONE_FOUND], true)) {
            throw new AdnInvalidResponseException;
        }

        $docs = $this->extractLoteDocuments($payload, requireNsu: false);

        if ($status === self::STATUS_NONE_FOUND && $docs !== []) {
            throw new AdnInvalidResponseException;
        }

        if ($status === self::STATUS_DOCUMENTS_FOUND && $docs === []) {
            throw new AdnInvalidResponseException;
        }

        return new EventsPageDto(
            accessKey: $accessKey,
            events: $docs,
            rawXml: $response['body'],
        );
    }

    /**
     * Aceita 2xx e 404 com envelope JSON de processamento (StatusProcessamento).
     * Demais 4xx/5xx seguem política permanente/retryable.
     */
    private function assertDistributionHttpOk(int $status, string $body): void
    {
        if ($status === 429) {
            throw new AdnRetryableException('ADN limitou temporariamente as requisições.', $status);
        }
        if ($status >= 500) {
            throw new AdnRetryableException('ADN apresentou indisponibilidade temporária.', $status);
        }
        if ($status >= 200 && $status < 300) {
            return;
        }
        // 404 oficial com envelope JSON de “nenhum documento” (E2220).
        // 404 JSON genérico (proxy/WAF) não deve bloquear o cursor como permanente.
        if ($status === 404 && $this->looksLikeJsonObject($body)) {
            if ($this->jsonHasProcessingStatus($body)) {
                return;
            }

            throw new AdnRetryableException(
                'ADN retornou 404 sem envelope de distribuição reconhecido.',
                $status,
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new AdnPermanentException('ADN rejeitou permanentemente a requisição.', $status);
        }
    }

    private function jsonHasProcessingStatus(string $body): bool
    {
        try {
            $payload = $this->decodeJsonObject($body);
        } catch (AdnInvalidResponseException) {
            return false;
        }

        return trim((string) ($payload['StatusProcessamento'] ?? '')) !== '';
    }

    private function looksLikeJsonObject(string $body): bool
    {
        $trimmed = ltrim($body);

        return $trimmed !== '' && $trimmed[0] === '{';
    }

    private function parseDistribution(string $body, int $requestedNsu): DistributionPageDto
    {
        $payload = $this->decodeJsonObject($body);
        $status = $this->stringField($payload, 'StatusProcessamento');

        if ($status === self::STATUS_REJECTION) {
            throw new AdnPermanentException($this->sanitizedRejectionMessage($payload));
        }

        if (! in_array($status, [self::STATUS_DOCUMENTS_FOUND, self::STATUS_NONE_FOUND], true)) {
            throw new AdnInvalidResponseException;
        }

        $docs = $this->extractLoteDocuments($payload, requireNsu: true);

        if ($status === self::STATUS_NONE_FOUND) {
            if ($docs !== []) {
                throw new AdnInvalidResponseException;
            }

            return new DistributionPageDto(
                status: $status,
                maxNsu: $requestedNsu,
                ultimoNsu: $requestedNsu,
                documents: [],
                hasMore: false,
                rawXml: $body,
            );
        }

        // DOCUMENTOS_LOCALIZADOS
        if ($docs === []) {
            throw new AdnInvalidResponseException;
        }

        $documentNsus = array_map(
            fn (DistributionDocumentDto $item): int => $item->nsu,
            $docs,
        );

        if (count($documentNsus) !== count(array_unique($documentNsus))
            || min($documentNsus) <= $requestedNsu) {
            throw new AdnInvalidResponseException;
        }

        $ultimo = max($documentNsus);
        // JSON oficial não envia maxNSU: hasMore pelo status + lote não vazio.
        $hasMore = true;

        return new DistributionPageDto(
            status: $status,
            maxNsu: $ultimo,
            ultimoNsu: $ultimo,
            documents: $docs,
            hasMore: $hasMore,
            rawXml: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<DistributionDocumentDto>
     */
    private function extractLoteDocuments(array $payload, bool $requireNsu): array
    {
        $lote = $payload['LoteDFe'] ?? [];
        if (! is_array($lote)) {
            throw new AdnInvalidResponseException;
        }

        if ($lote === []) {
            return [];
        }

        $documents = [];
        foreach ($lote as $item) {
            if (! is_array($item)) {
                throw new AdnInvalidResponseException;
            }

            $rawNsu = $item['NSU'] ?? $item['nsu'] ?? null;
            if ($requireNsu) {
                $nsu = $this->parseNsuValue($rawNsu);
            } else {
                $nsu = $rawNsu === null || $rawNsu === '' ? 0 : $this->parseNsuValue($rawNsu);
            }

            $tipo = strtoupper(trim((string) ($item['TipoDocumento'] ?? '')));
            $type = match ($tipo) {
                'NFSE', 'NFS-E' => AdnDocumentType::Nfse,
                'EVENTO' => AdnDocumentType::Event,
                default => AdnDocumentType::Unknown,
            };

            $arquivo = trim((string) ($item['ArquivoXml'] ?? ''));
            if ($arquivo === '') {
                throw new AdnInvalidResponseException;
            }

            $schema = $this->schemaHintForType($type, $item);
            $accessKey = $this->normalizeAccessKey(
                isset($item['ChaveAcesso']) ? (string) $item['ChaveAcesso'] : null,
            );

            $documents[] = new DistributionDocumentDto(
                nsu: $nsu,
                type: $type,
                schema: $schema,
                contentBase64: $arquivo,
                accessKey: $accessKey,
            );
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function schemaHintForType(AdnDocumentType $type, array $item): string
    {
        $explicit = trim((string) ($item['Schema'] ?? $item['schema'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return match ($type) {
            AdnDocumentType::Nfse => 'NFSe_v1.00.xsd',
            AdnDocumentType::Event => 'evento_v1.00.xsd',
            AdnDocumentType::Unknown => 'unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $body): array
    {
        $trimmed = ltrim($body);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            throw new AdnInvalidResponseException;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AdnInvalidResponseException;
        }

        if (! is_array($decoded)) {
            throw new AdnInvalidResponseException;
        }

        // Resposta de topo deve ser objeto JSON (associativo).
        if (array_is_list($decoded) && $decoded !== []) {
            throw new AdnInvalidResponseException;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new AdnInvalidResponseException;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sanitizedRejectionMessage(array $payload): string
    {
        $erros = $payload['Erros'] ?? null;
        if (! is_array($erros) || $erros === []) {
            return 'ADN rejeitou a consulta de distribuição.';
        }

        $first = $erros[0];
        if (! is_array($first)) {
            return 'ADN rejeitou a consulta de distribuição.';
        }

        $code = trim((string) ($first['Codigo'] ?? $first['codigo'] ?? ''));
        if ($code !== '' && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $code)) {
            return 'ADN rejeitou a consulta de distribuição ('.$code.').';
        }

        return 'ADN rejeitou a consulta de distribuição.';
    }

    private function parseNsuValue(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new AdnInvalidResponseException;
            }

            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (! preg_match('/^(0|[1-9][0-9]{0,18})$/', $value)) {
                throw new AdnInvalidResponseException;
            }

            $nsu = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($nsu === false) {
                throw new AdnInvalidResponseException;
            }

            return $nsu;
        }

        throw new AdnInvalidResponseException;
    }

    private function normalizeAccessKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        // Coluna access_key (50); chave nacional típica 44–50.
        if (strlen($normalized) > 50) {
            throw new AdnInvalidResponseException;
        }

        return $normalized;
    }
}
