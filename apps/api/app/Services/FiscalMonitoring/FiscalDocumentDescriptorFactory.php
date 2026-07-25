<?php

namespace App\Services\FiscalMonitoring;

use App\DTO\Fiscal\FiscalDocumentDescriptorDto;
use App\Enums\DocumentUnavailableReason;
use App\Enums\MonitoringDocumentPolicy;
use App\Enums\MonitoringResultKind;
use App\Models\FiscalEvidenceArtifact;
use App\Models\Office;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceContract;

/** Fonte única para descritores públicos de evidência do workspace. */
final class FiscalDocumentDescriptorFactory
{
    public function __construct(
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    public function forSurface(
        Office $office,
        MonitoringSurfaceContract $surface,
        ?FiscalEvidenceArtifact $artifact = null,
    ): FiscalDocumentDescriptorDto {
        $surfaceReason = $this->unavailableReasonForSurface($surface);
        if ($surfaceReason !== null) {
            return FiscalDocumentDescriptorDto::unavailable($surfaceReason, $surface);
        }
        if ($artifact === null) {
            return FiscalDocumentDescriptorDto::unavailable(
                DocumentUnavailableReason::NotCollected,
                $surface,
            );
        }

        $artifactReason = $this->evidenceStore->unavailableReason(
            $artifact,
            (int) $office->id,
        );
        if ($artifactReason !== null) {
            return FiscalDocumentDescriptorDto::unavailable($artifactReason, $surface);
        }

        return FiscalDocumentDescriptorDto::fromAvailableArtifact($artifact, $surface);
    }

    private function unavailableReasonForSurface(
        MonitoringSurfaceContract $surface,
    ): ?DocumentUnavailableReason {
        if ($surface->resultKind === MonitoringResultKind::Unavailable) {
            return DocumentUnavailableReason::NotProduction;
        }

        if (! $surface->allowsDocument
            || $surface->documentPolicy === MonitoringDocumentPolicy::Never
        ) {
            return match ($surface->resultKind) {
                MonitoringResultKind::Structured => DocumentUnavailableReason::StructuredOnly,
                MonitoringResultKind::Unavailable => DocumentUnavailableReason::NotProduction,
                default => DocumentUnavailableReason::NotSupported,
            };
        }

        return null;
    }
}
