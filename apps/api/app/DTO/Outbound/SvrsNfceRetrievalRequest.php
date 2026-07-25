<?php

namespace App\DTO\Outbound;

/**
 * Request tipado para o cliente SVRS — sem HTML, sem material criptográfico no DTO de job.
 * Certificado é passado separadamente ao transporte (BLOB em memória).
 */
final readonly class SvrsNfceRetrievalRequest
{
    public function __construct(
        public string $accessKey,
        public string $environment, // production | homologation
        public string $correlationId,
        public int $officeId,
        public int $profileId,
        public int $clientId,
        public int $establishmentId,
    ) {
        if (! preg_match('/^[A-Z0-9]{44}$/', $this->accessKey)) {
            throw new \InvalidArgumentException('Chave de acesso inválida para recuperação SVRS.');
        }
        if (! in_array($this->environment, ['production', 'homologation'], true)) {
            throw new \InvalidArgumentException('Ambiente fiscal inválido.');
        }
    }

    /**
     * Ambiente no formulário SVRS: 1=produção, 2=homologação.
     */
    public function portalAmbiente(): string
    {
        return $this->environment === 'production' ? '1' : '2';
    }
}
