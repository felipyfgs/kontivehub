<?php

namespace App\DTO\Cnpj;

/**
 * Sócio sanitizado — documento sempre mascarado (nunca CPF/CNPJ cru).
 */
final class ShareholderData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?string $qualificationCode = null,
        public readonly ?string $qualificationName = null,
        public readonly ?string $enteredAt = null,
        public readonly ?string $documentMasked = null,
    ) {}

    /**
     * @return array{
     *   name: string,
     *   type: ?string,
     *   qualification_code: ?string,
     *   qualification_name: ?string,
     *   entered_at: ?string,
     *   document_masked: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'qualification_code' => $this->qualificationCode,
            'qualification_name' => $this->qualificationName,
            'entered_at' => $this->enteredAt,
            'document_masked' => $this->documentMasked,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $name = self::nullableString($data['name'] ?? null);
        if ($name === null) {
            return null;
        }

        return new self(
            name: $name,
            type: self::nullableString($data['type'] ?? null),
            qualificationCode: self::nullableString($data['qualification_code'] ?? null),
            qualificationName: self::nullableString($data['qualification_name'] ?? null),
            enteredAt: self::nullableString($data['entered_at'] ?? null),
            documentMasked: DocumentMask::ensureMasked($data['document_masked'] ?? $data['document'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
