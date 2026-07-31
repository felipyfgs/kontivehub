<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SerproTermDraftData;
use App\DTO\Tenant\SerproTermDraftResult;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class GenerateTenantSerproTermDraftAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
    ) {}

    public function __invoke(
        SerproTermDraftData $data,
    ): SerproTermDraftResult {
        try {
            $result = $this->authorizations->generateTermoDraft(
                $this->currentTenant->tenant(),
                $data->environment,
                $data->validUntil,
                $data->actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::operationFailed(
                $error->getMessage(),
            );
        }

        return new SerproTermDraftResult(
            authorization: $result['auth'],
            draftSha256: $result['draft_sha256'],
        );
    }
}
