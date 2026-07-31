<?php

namespace App\Http\Resources;

use App\DTO\Tenant\IntegrationRefreshData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IntegrationRefreshData */
final class TenantIntegrationRefreshResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var IntegrationRefreshData $result */
        $result = $this->resource;
        $payload = [
            'status' => $result->status,
            'procurador_token_expires_at' => $result->procuradorTokenExpiresAt,
            'has_procurador_token' => $result->hasProcuradorToken,
        ];

        if ($result->onboardingEvaluated) {
            $payload['onboarding_evaluated'] = true;
        }

        return $payload;
    }
}
