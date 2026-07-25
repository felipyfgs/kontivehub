<?php

namespace App\DTO\Esocial;

use InvalidArgumentException;

final readonly class EsocialBxHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public string $contentType = 'text/xml',
    ) {
        if ($this->status < 100 || $this->status > 599) {
            throw new InvalidArgumentException('Status HTTP eSocial BX inválido.');
        }
        if (strlen($this->body) > 20 * 1024 * 1024) {
            throw new InvalidArgumentException('Resposta HTTP eSocial BX acima do limite.');
        }
        if (strlen($this->contentType) > 120 || preg_match('/[\r\n]/', $this->contentType) === 1) {
            throw new InvalidArgumentException('Content-Type eSocial BX inválido.');
        }
    }

    /** @return array{status:int,content_type:string,byte_size:int,body_sha256:string} */
    public function toSanitizedArray(): array
    {
        return [
            'status' => $this->status,
            'content_type' => $this->contentType,
            'byte_size' => strlen($this->body),
            'body_sha256' => hash('sha256', $this->body),
        ];
    }
}
