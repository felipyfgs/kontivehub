<?php

namespace App\DTO\Cnpj;

use JsonSerializable;

/**
 * DTO sanitizado da consulta cadastral — único formato permitido em cache, API e logs.
 */
final class CnpjRegistrationLookupResult implements JsonSerializable
{
    /**
     * @param  list<string>  $sourcesUsed
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $sourceUpdatedAt,
        public readonly ClientRegistrationData $client,
        public readonly EstablishmentRegistrationData $establishment,
        public readonly array $sourcesUsed = [],
    ) {}

    /**
     * @return array{
     *   source: string,
     *   source_updated_at: ?string,
     *   sources_used: list<string>,
     *   client: array<string, mixed>,
     *   establishment: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $sources = $this->sourcesUsed !== [] ? $this->sourcesUsed : [$this->source];

        return [
            'source' => $this->source,
            'source_updated_at' => $this->sourceUpdatedAt,
            'sources_used' => array_values(array_unique($sources)),
            'client' => $this->client->toArray(),
            'establishment' => $this->establishment->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $client = is_array($data['client'] ?? null) ? $data['client'] : [];
        $establishment = is_array($data['establishment'] ?? null) ? $data['establishment'] : [];
        $sourcesUsed = [];
        foreach ($data['sources_used'] ?? [] as $source) {
            if (is_string($source) && $source !== '') {
                $sourcesUsed[] = $source;
            }
        }

        $primary = (string) ($data['source'] ?? 'CNPJ_WS');

        return new self(
            source: $primary,
            sourceUpdatedAt: self::nullableString($data['source_updated_at'] ?? null),
            client: ClientRegistrationData::fromArray($client),
            establishment: EstablishmentRegistrationData::fromArray($establishment),
            sourcesUsed: $sourcesUsed !== [] ? $sourcesUsed : [$primary],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
