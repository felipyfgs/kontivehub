<?php

namespace App\Exceptions;

use App\Enums\EsocialBxFailureClass;
use InvalidArgumentException;
use RuntimeException;

final class EsocialBxException extends RuntimeException
{
    public function __construct(
        public readonly string $stableCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $blocked = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $officialCode = null,
        ?\Throwable $previous = null,
    ) {
        if (preg_match('/^ESOCIAL_BX_[A-Z0-9_]+$/', $this->stableCode) !== 1) {
            throw new InvalidArgumentException('Código estável eSocial BX inválido.');
        }
        if ($this->httpStatus !== null && ($this->httpStatus < 100 || $this->httpStatus > 599)) {
            throw new InvalidArgumentException('Status HTTP eSocial BX inválido.');
        }
        if ($this->officialCode !== null && preg_match('/^\d{3}$/', $this->officialCode) !== 1) {
            throw new InvalidArgumentException('Código oficial eSocial BX inválido.');
        }
        parent::__construct($message, 0, $previous);
    }

    public function classification(): EsocialBxFailureClass
    {
        if ($this->blocked) {
            return EsocialBxFailureClass::Blocked;
        }

        return $this->retryable ? EsocialBxFailureClass::Retryable : EsocialBxFailureClass::Permanent;
    }

    /** @return array{code:string,class:string,retryable:bool,blocked:bool,http_status:?int,official_code:?string} */
    public function toSanitizedArray(): array
    {
        return [
            'code' => $this->stableCode,
            'class' => $this->classification()->value,
            'retryable' => $this->retryable,
            'blocked' => $this->blocked,
            'http_status' => $this->httpStatus,
            'official_code' => $this->officialCode,
        ];
    }
}
