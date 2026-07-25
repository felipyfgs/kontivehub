<?php

namespace App\DTO\Esocial;

/**
 * Resultado de consulta eSocial — apenas eventos oficiais admitidos.
 *
 * @phpstan-type EventList list<EsocialEventDto>
 */
final readonly class EsocialFetchResult
{
    /**
     * @param  list<EsocialEventDto>  $events
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $events = [],
        public bool $success = true,
        public bool $partial = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        /** Ex.: fonte ainda sem integração M2M real. */
        public bool $sourceUnsupported = false,
        public array $diagnostics = [],
    ) {}

    public static function emptySuccess(): self
    {
        return new self(events: [], success: true);
    }

    public static function unsupported(string $explanation): self
    {
        return new self(
            events: [],
            success: true,
            sourceUnsupported: true,
            diagnostics: ['explanation' => $explanation],
        );
    }

    public static function failed(string $message, string $code = 'ESOCIAL_FETCH_FAILED'): self
    {
        return new self(
            events: [],
            success: false,
            errorCode: $code,
            errorMessage: $message,
        );
    }

    /**
     * @param  list<EsocialEventDto>  $events
     */
    public static function withEvents(array $events, bool $partial = false): self
    {
        return new self(events: $events, success: true, partial: $partial);
    }
}
