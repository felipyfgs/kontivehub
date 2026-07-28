<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Models\Client;
use App\Models\DctfwebEvidenceVersion;
use App\Models\Tenant;
use App\Services\Fiscal\Dctfweb\DctfwebMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Throwable;

final readonly class ReadDctfwebEvidenceAction
{
    public function __construct(
        private DctfwebMonitoringQueryService $queries,
        private FiscalEvidenceStore $evidenceStore,
    ) {}

    public function handle(
        Tenant $tenant,
        int $evidenceId,
        ?Client $client = null,
    ): ?FiscalDownloadData {
        $version = $client instanceof Client
            ? $this->queries->findEvidenceVersion(
                $tenant,
                $client,
                $evidenceId,
            )
            : $this->queries->findEvidenceVersionForTenant(
                $tenant,
                $evidenceId,
            );
        if (! $version instanceof DctfwebEvidenceVersion) {
            return null;
        }

        $version->loadMissing('artifact');
        if ($version->artifact === null) {
            return null;
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $version->artifact,
                (int) $tenant->id,
            );
        } catch (Throwable) {
            return null;
        }

        $document = $this->queries->documentMetadata($version);

        return new FiscalDownloadData(
            bytes: $bytes,
            contentType: $document['content_type'],
            filename: $document['filename'],
        );
    }
}
