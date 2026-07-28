<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Models\PgdasdArtifact;
use App\Models\Tenant;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Throwable;

final readonly class ReadPgdasdArtifactAction
{
    public function __construct(
        private PgdasdMonitoringQueryService $queries,
        private FiscalEvidenceStore $evidenceStore,
    ) {}

    public function handle(Tenant $tenant, int $artifactId): ?FiscalDownloadData
    {
        $artifact = $this->queries->findArtifactForTenant(
            $tenant,
            $artifactId,
        );
        if (! $artifact instanceof PgdasdArtifact) {
            return null;
        }

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
            filename: $this->sanitizeFilename(
                $artifact->filename,
                (string) $artifact->kind,
                (int) $artifact->id,
            ),
        );
    }

    private function sanitizeFilename(
        ?string $filename,
        string $kind,
        int $id,
    ): string {
        $fallback = 'pgdasd-'.$this->safeToken($kind, 'doc').'-'.$id.'.pdf';
        if ($filename === null || trim($filename) === '') {
            return $fallback;
        }

        $base = basename(str_replace(["\0", '\\'], ['', '/'], $filename));
        $base = preg_replace('/[^\w.\-]+/u', '_', $base) ?? '';
        $base = trim($base, '._');

        if ($base === '' || $base === '.' || $base === '..') {
            return $fallback;
        }

        return mb_substr($base, 0, 180);
    }

    private function safeToken(string $value, string $default): string
    {
        $token = preg_replace('/[^\w\-]+/u', '_', $value) ?? '';
        $token = trim($token, '_');

        return $token !== '' ? mb_substr($token, 0, 40) : $default;
    }
}
