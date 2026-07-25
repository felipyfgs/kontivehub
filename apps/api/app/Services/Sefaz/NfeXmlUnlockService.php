<?php

namespace App\Services\Sefaz;

use App\Enums\NfeManifestationType;
use App\Models\NfeDocument;

/**
 * Desbloqueio de XML completo (procNFe) a partir de resumo (resNFe).
 * Delega ciência técnica ao NfeManifestationService (RecepcaoEvento4).
 */
final class NfeXmlUnlockService
{
    public function __construct(
        private readonly NfeManifestationService $manifestation,
    ) {}

    /**
     * @return array{status: string, has_full_xml: bool, message: string, c_stat?: string|null, protocol?: string|null, manifestation_status?: string|null, type?: string|null, x_motivo?: string|null}
     */
    public function unlock(string $accessKey, int $officeId): array
    {
        $full = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->first();

        if ($full !== null) {
            return [
                'status' => 'already_full',
                'has_full_xml' => true,
                'message' => 'XML completo já disponível para download.',
            ];
        }

        $summary = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->where('is_summary', true)
            ->first();

        if ($summary === null) {
            return [
                'status' => 'not_found',
                'has_full_xml' => false,
                'message' => 'Documento NF-e não encontrado neste escritório.',
            ];
        }

        return $this->manifestation->manifest(
            $accessKey,
            $officeId,
            NfeManifestationType::Ciencia,
            purpose: 'UNLOCK_XML',
        );
    }
}
