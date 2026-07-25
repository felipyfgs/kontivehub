<?php

namespace App\DTO\Cnpj;

final class CnaeData
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $name = null,
    ) {}

    /**
     * @return array{code: string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $code = self::nullableString($data['code'] ?? $data['id'] ?? $data['codigo'] ?? null);
        if ($code === null) {
            return null;
        }

        return new self(
            code: $code,
            name: self::nullableString($data['name'] ?? $data['descricao'] ?? null),
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
