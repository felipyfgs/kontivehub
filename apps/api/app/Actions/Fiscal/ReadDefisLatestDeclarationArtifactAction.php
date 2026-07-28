<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Models\DefisLatestDeclarationArtifact;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Throwable;

final readonly class ReadDefisLatestDeclarationArtifactAction
{
    public function __construct(
        private FiscalEvidenceStore $evidenceStore,
    ) {}

    public function handle(
        Tenant $tenant,
        DefisLatestDeclarationArtifact $artifact,
    ): ?FiscalDownloadData {
        $artifact->loadMissing('evidenceArtifact');
        if ($artifact->evidenceArtifact === null) {
            return null;
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $artifact->evidenceArtifact,
                (int) $tenant->id,
            );
        } catch (Throwable) {
            return null;
        }

        return new FiscalDownloadData(
            bytes: $bytes,
            contentType: $artifact->content_type ?: 'application/pdf',
            filename: 'defis-'.$artifact->id.'.pdf',
        );
    }
}
