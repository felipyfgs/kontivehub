<?php

namespace App\DTO\Serpro;

use App\Enums\AuthorIdentityType;
use InvalidArgumentException;

/**
 * Pedido de domínio para Integra Contador.
 * operation_key é obrigatório; coordenadas e headers oficiais vêm do catálogo.
 *
 * @phpstan-type Payload array<string, mixed>
 */
final class IntegraRequest
{
    public readonly int $officeId;

    public readonly int $clientId;

    public readonly string $environment;

    public readonly string $contractorCnpj;

    public readonly string $authorIdentity;

    public readonly string $contributorCnpj;

    public readonly string $operationKey;

    /** @var array<string, mixed> */
    public readonly array $businessData;

    /** @var array<string, mixed> */
    public readonly array $payload;

    /** @var array<string, string> */
    public readonly array $headers;

    public readonly ?string $idempotencyKey;

    public readonly ?string $correlationId;

    public readonly ?string $requestTag;

    public readonly bool $isMutating;

    /** @deprecated Coordenadas vêm do catálogo via operationKey */
    public readonly ?string $solutionCode;

    /** @deprecated */
    public readonly ?string $serviceCode;

    /** @deprecated */
    public readonly ?string $operationCode;

    public readonly FiscalIdentity $author;

    public readonly FiscalIdentity $contributor;

    /** @var null|array{numero: string, tipo: 3|4} */
    public readonly ?array $contributorEnvelope;

    /**
     * @param  array<string, mixed>  $businessData  Dados de negócio (protocolo, filtros…) — não credenciais
     * @param  array<string, string>  $headers  Headers extras não secretos (não sobrescreve oficiais)
     * @param  array<string, mixed>  $payload  Legado: envelope parcial; preferir businessData
     */
    public function __construct(
        int $officeId,
        int $clientId,
        string $environment,
        string $contractorCnpj,
        string $authorIdentity,
        string $contributorCnpj,
        string $operationKey,
        array $businessData = [],
        array $payload = [],
        array $headers = [],
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?string $requestTag = null,
        bool $isMutating = false,
        ?string $solutionCode = null,
        ?string $serviceCode = null,
        ?string $operationCode = null,
        ?FiscalIdentity $author = null,
        ?FiscalIdentity $contributor = null,
        ?EventosBatchContributor $eventosBatchContributor = null,
    ) {
        $key = trim($operationKey);
        if ($key === '') {
            throw new InvalidArgumentException('operation_key é obrigatório no IntegraRequest.');
        }

        $this->officeId = $officeId;
        $this->clientId = $clientId;
        $this->environment = $environment;
        $this->operationKey = $key;
        $this->businessData = $businessData;
        $this->payload = $payload;
        $this->headers = $headers;
        $this->idempotencyKey = $idempotencyKey;
        $this->correlationId = $correlationId;
        $this->requestTag = $requestTag;
        $this->isMutating = $isMutating;
        $this->solutionCode = $solutionCode;
        $this->serviceCode = $serviceCode;
        $this->operationCode = $operationCode;

        $this->author = $author === null
            ? FiscalIdentity::fromNumero($authorIdentity)
            : new FiscalIdentity($author->tipo, $author->numero);
        $this->contributor = $contributor === null
            ? FiscalIdentity::fromNumero($contributorCnpj)
            : new FiscalIdentity($contributor->tipo, $contributor->numero);
        $this->authorIdentity = $this->author->numero;
        $this->contributorCnpj = $this->contributor->numero;
        $this->contractorCnpj = FiscalIdentity::fromNumero($contractorCnpj, AuthorIdentityType::Cnpj)->numero;
        $this->contributorEnvelope = $eventosBatchContributor?->toEnvelope();
    }

    /**
     * Tag de correlação determinística (máx. 32 chars) para X-Request-Tag.
     * Opaca: hash sem NI/CNPJ em claro.
     */
    public function resolvedRequestTag(): string
    {
        if ($this->requestTag !== null && $this->requestTag !== '') {
            return substr($this->requestTag, 0, 32);
        }

        $seed = implode('|', [
            (string) $this->officeId,
            (string) $this->clientId,
            $this->operationKey,
            $this->idempotencyKey ?? '',
            $this->correlationId ?? '',
        ]);

        return substr(hash('sha256', $seed), 0, 32);
    }
}
