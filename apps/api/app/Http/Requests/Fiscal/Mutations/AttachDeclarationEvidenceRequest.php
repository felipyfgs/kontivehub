<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\AttachDeclarationEvidenceData;
use App\Enums\TaxDeliveryEvidenceKind;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class AttachDeclarationEvidenceRequest extends DeclarationHubWriteRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(TaxDeliveryEvidenceKind::class)],
            'protocol_number' => ['nullable', 'string', 'max:80'],
            'receipt_number' => ['nullable', 'string', 'max:80'],
            'source' => ['required', 'string', 'max:80'],
            'source_version' => ['nullable', 'string', 'max:40'],
            'observed_at' => ['nullable', 'date'],
            'evidence_artifact_id' => ['nullable', 'integer'],
            'run_id' => ['nullable', 'integer'],
            'payload_digest' => ['nullable', 'string', 'size:64'],
            'metadata' => ['nullable', 'array'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function evidenceData(): AttachDeclarationEvidenceData
    {
        $data = $this->validated();

        return new AttachDeclarationEvidenceData(
            kind: TaxDeliveryEvidenceKind::from((string) $data['kind']),
            protocolNumber: $data['protocol_number'] ?? null,
            receiptNumber: $data['receipt_number'] ?? null,
            source: (string) $data['source'],
            sourceVersion: $data['source_version'] ?? null,
            observedAt: isset($data['observed_at'])
                ? CarbonImmutable::parse($data['observed_at'])
                : null,
            evidenceArtifactId: isset($data['evidence_artifact_id'])
                ? (int) $data['evidence_artifact_id']
                : null,
            runId: isset($data['run_id']) ? (int) $data['run_id'] : null,
            payloadDigest: $data['payload_digest'] ?? null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        );
    }
}
