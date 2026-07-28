<?php

namespace App\Exceptions;

use LogicException;
use RuntimeException;

abstract class ApiDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $responseData
     * @param  array<string, string>  $responseHeaders
     */
    protected function __construct(
        private readonly string $stableCode,
        private readonly string $safeMessage,
        private readonly int $httpStatus,
        private readonly array $responseData = [],
        private readonly array $responseHeaders = [],
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{2,127}$/', $stableCode) !== 1) {
            throw new LogicException('Código estável de exception de API inválido.');
        }
        if (trim($safeMessage) === '') {
            throw new LogicException('Mensagem segura de exception de API é obrigatória.');
        }
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new LogicException('Status HTTP de exception de API inválido.');
        }

        parent::__construct($stableCode);
    }

    final public function stableCode(): string
    {
        return $this->stableCode;
    }

    final public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    final public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    final public function responseData(): array
    {
        return $this->responseData;
    }

    /** @return array<string, string> */
    final public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }
}
