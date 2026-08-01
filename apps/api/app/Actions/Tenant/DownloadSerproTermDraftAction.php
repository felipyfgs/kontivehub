<?php

namespace App\Actions\Tenant;

use App\Enums\SerproEnvironment;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class DownloadSerproTermDraftAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        SerproEnvironment $environment,
        int $actorUserId,
    ): string {
        $tenant = $this->currentTenant->tenant();

        try {
            $xml = $this->authorizations->getTermoDraftXml($tenant, $environment);
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::termDraftNotFound(
                $error->getMessage(),
            );
        }

        $this->audit->record('serpro.authorization.termo_draft_download', 'SUCCESS', null, [
            'environment' => $environment->value,
            'bytes' => strlen($xml),
        ], $actorUserId, $tenant->id);

        return $xml;
    }
}
