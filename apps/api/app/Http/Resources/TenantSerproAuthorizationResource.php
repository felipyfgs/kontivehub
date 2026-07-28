<?php

namespace App\Http\Resources;

use App\Models\TenantSerproAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantSerproAuthorization */
final class TenantSerproAuthorizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantSerproAuthorization $authorization */
        $authorization = $this->resource;

        return [
            'id' => $authorization->id,
            'tenant_id' => $authorization->tenant_id,
            'environment' => $authorization->environment->value,
            'status' => $authorization->status->value,
            'author_identity_type' => $authorization->author_identity_type->value,
            'author_identity_masked' => $this->mask($authorization->author_identity),
            'author_name' => $authorization->author_name,
            'certificate_mode' => $authorization->certificate_mode->value,
            'has_termo' => $authorization->termo_vault_object_id !== null,
            'termo_sha256' => $authorization->termo_sha256,
            'termo_valid_from' => $authorization->termo_valid_from?->toIso8601String(),
            'termo_valid_to' => $authorization->termo_valid_to?->toIso8601String(),
            'termo_destination_cnpj_masked' => $authorization->termo_destination_cnpj
                ? $this->mask($authorization->termo_destination_cnpj)
                : null,
            'termo_signed_by_masked' => $authorization->termo_signed_by
                ? $this->mask($authorization->termo_signed_by)
                : null,
            'termo_uploaded_at' => $authorization->termo_uploaded_at?->toIso8601String(),
            'termo_authorization_state' => $authorization->termo_authorization_state?->value,
            'has_procurador_token' => $authorization->procurador_token_vault_object_id !== null
                && $authorization->procurador_token_expires_at !== null
                && $authorization->procurador_token_expires_at->isFuture(),
            'procurador_token_expires_at' => $authorization->procurador_token_expires_at?->toIso8601String(),
            'has_procurador_etag' => filled($authorization->procurador_etag),
            'last_token_refresh_at' => $authorization->last_token_refresh_at?->toIso8601String(),
            'last_validation_result' => $authorization->last_validation_result,
            'last_validation_message' => $authorization->last_validation_message,
            'last_validated_at' => $authorization->last_validated_at?->toIso8601String(),
            'action_required_reason' => $authorization->action_required_reason,
            'actions_required' => $authorization->computeActionsRequired(),
            'created_at' => $authorization->created_at?->toIso8601String(),
            'updated_at' => $authorization->updated_at?->toIso8601String(),
        ];
    }

    private function mask(string $value): string
    {
        $normalized = preg_replace('/\D/', '', $value) ?: $value;
        $length = strlen($normalized);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)).substr($normalized, -4);
    }
}
