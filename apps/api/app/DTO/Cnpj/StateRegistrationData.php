<?php

namespace App\DTO\Cnpj;

final class StateRegistrationData
{
    public function __construct(
        public readonly string $number,
        public readonly ?string $state = null,
        public readonly ?bool $active = null,
    ) {}

    /**
     * @return array{number: string, state: ?string, active: ?bool}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'state' => $this->state,
            'active' => $this->active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $number = self::nullableString($data['number'] ?? $data['inscricao_estadual'] ?? null);
        if ($number === null) {
            return null;
        }

        $active = $data['active'] ?? $data['ativo'] ?? null;

        return new self(
            number: $number,
            state: self::nullableString(
                $data['state']
                    ?? (is_array($data['estado'] ?? null) ? ($data['estado']['sigla'] ?? null) : null)
            ),
            active: is_bool($active) ? $active : null,
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
