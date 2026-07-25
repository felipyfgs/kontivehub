<?php

namespace App\DTO\Serpro;

/**
 * Cadeia de representação: contratante (API) → autor (Termo) → contribuinte (procuração).
 * Nenhuma coincidência entre identidades é presumida.
 */
final readonly class RepresentationChain
{
    public function __construct(
        public string $contractorCnpj,
        public string $authorIdentity,
        public string $authorIdentityType,
        public string $contributorCnpj,
        public int $officeId,
        public int $clientId,
        public string $environment,
        public bool $complete,
        /** @var list<string> */
        public array $missingLinks = [],
        public ?string $blockReason = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->complete && $this->missingLinks === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'office_id' => $this->officeId,
            'client_id' => $this->clientId,
            'environment' => $this->environment,
            'contractor_cnpj_masked' => self::mask($this->contractorCnpj),
            'author_identity_masked' => self::mask($this->authorIdentity),
            'author_identity_type' => $this->authorIdentityType,
            'contributor_cnpj_masked' => self::mask($this->contributorCnpj),
            'complete' => $this->isComplete(),
            'missing_links' => $this->missingLinks,
            'block_reason' => $this->blockReason,
        ];
    }

    private static function mask(string $value): string
    {
        $value = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 2).str_repeat('*', max(0, $len - 6)).substr($value, -4);
    }
}
