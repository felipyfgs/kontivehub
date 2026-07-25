<?php

namespace App\DTO\Fiscal;

use App\Enums\DocumentUnavailableReason;
use App\Models\FiscalEvidenceArtifact;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceContract;

/**
 * Descritor público de documento/evidência tenant-scoped.
 * href gerado no servidor; sem vault_object_id, hash, operation_key, run_id ou path.
 */
final readonly class FiscalDocumentDescriptorDto
{
    public function __construct(
        public bool $available,
        public ?string $kind,
        public ?string $label,
        public ?string $contentType,
        public ?string $observedAt,
        public ?string $sourceSurface,
        public ?string $sourceLabel,
        public ?string $href,
        public ?DocumentUnavailableReason $unavailableReason,
    ) {}

    /**
     * Constrói o descritor após validação pelo FiscalDocumentDescriptorFactory.
     */
    public static function fromAvailableArtifact(
        FiscalEvidenceArtifact $artifact,
        MonitoringSurfaceContract $surface,
    ): self {
        $contentType = is_string($artifact->content_type) && $artifact->content_type !== ''
            ? $artifact->content_type
            : 'application/octet-stream';

        return new self(
            available: true,
            kind: self::kindForContentType($contentType),
            label: self::labelForSurface($surface),
            contentType: $contentType,
            observedAt: $artifact->observed_at?->toIso8601String(),
            sourceSurface: $surface->surfaceKey,
            sourceLabel: $surface->sourceLabel,
            href: '/api/v1/fiscal/evidence/'.$artifact->id.'/download',
            unavailableReason: null,
        );
    }

    public static function unavailable(
        DocumentUnavailableReason $reason,
        MonitoringSurfaceContract $surface,
    ): self {
        return new self(
            available: false,
            kind: null,
            label: null,
            contentType: null,
            observedAt: null,
            sourceSurface: $surface->surfaceKey,
            sourceLabel: $surface->sourceLabel,
            href: null,
            unavailableReason: $reason,
        );
    }

    /**
     * @return array{
     *   available: bool,
     *   kind: string|null,
     *   label: string|null,
     *   content_type: string|null,
     *   observed_at: string|null,
     *   source_surface: string|null,
     *   source_label: string|null,
     *   href: string|null,
     *   unavailable_reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'kind' => $this->kind,
            'label' => $this->label,
            'content_type' => $this->contentType,
            'observed_at' => $this->observedAt,
            'source_surface' => $this->sourceSurface,
            'source_label' => $this->sourceLabel,
            'href' => $this->href,
            'unavailable_reason' => $this->unavailableReason?->value,
        ];
    }

    private static function labelForSurface(MonitoringSurfaceContract $surface): string
    {
        return match ($surface->surfaceKey) {
            'sitfis' => 'Ver relatório oficial',
            'simples_mei_pgdasd' => 'Ver declaração/recibo',
            'simples_mei_pgmei' => 'Baixar DAS',
            'simples_mei_regime' => 'Ver demonstrativo oficial',
            'dctfweb' => 'Ver recibo',
            'installments' => 'Baixar documento de arrecadação',
            'guides' => 'Ver guia',
            'declarations' => 'Ver recibo/evidência',
            default => 'Ver documento oficial',
        };
    }

    private static function kindForContentType(string $contentType): string
    {
        $normalized = strtolower($contentType);

        return match (true) {
            str_contains($normalized, 'pdf') => 'PDF',
            str_contains($normalized, 'xml') => 'XML',
            str_contains($normalized, 'zip') => 'ZIP',
            str_contains($normalized, 'json') => 'JSON',
            default => 'DOCUMENT',
        };
    }
}
