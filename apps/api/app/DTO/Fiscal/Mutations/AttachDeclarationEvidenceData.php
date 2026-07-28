<?php

namespace App\DTO\Fiscal\Mutations;

use App\Enums\TaxDeliveryEvidenceKind;
use Carbon\CarbonImmutable;

final readonly class AttachDeclarationEvidenceData
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public TaxDeliveryEvidenceKind $kind,
        public ?string $protocolNumber,
        public ?string $receiptNumber,
        public string $source,
        public ?string $sourceVersion,
        public ?CarbonImmutable $observedAt,
        public ?int $evidenceArtifactId,
        public ?int $runId,
        public ?string $payloadDigest,
        public ?array $metadata,
    ) {}

    /** @return array<string, mixed> */
    public function toServicePayload(): array
    {
        return [
            'kind' => $this->kind,
            'protocol_number' => $this->protocolNumber,
            'receipt_number' => $this->receiptNumber,
            'source' => $this->source,
            'source_version' => $this->sourceVersion,
            'observed_at' => $this->observedAt,
            'evidence_artifact_id' => $this->evidenceArtifactId,
            'run_id' => $this->runId,
            'payload_digest' => $this->payloadDigest,
            'metadata' => $this->metadata,
        ];
    }
}
