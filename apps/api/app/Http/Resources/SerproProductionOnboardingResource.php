<?php

namespace App\Http\Resources;

use App\Models\SerproProductionOnboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproProductionOnboarding */
final class SerproProductionOnboardingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproProductionOnboarding $onboarding */
        $onboarding = $this->resource;

        return [
            'id' => $onboarding->id,
            'tenant_id' => $onboarding->tenant_id,
            'environment' => $onboarding->environment?->value,
            'status' => $onboarding->status?->value,
            'current_step' => $onboarding->current_step?->value,
            'completed_steps' => array_values(
                is_array($onboarding->completed_steps) ? $onboarding->completed_steps : [],
            ),
            'correlation_id' => $onboarding->correlation_id,
            'consent' => [
                'version' => $onboarding->consent_version,
                'text_sha256' => $onboarding->consent_text_sha256,
                'consented_at' => $onboarding->consented_at?->toIso8601String(),
                'actor_user_id' => $onboarding->actor_user_id,
            ],
            'credential_version_id' => $onboarding->serpro_credential_version_id,
            'authorization_id' => $onboarding->tenant_serpro_authorization_id,
            'rollout_approval_id' => $onboarding->serpro_rollout_approval_id,
            'initial_mailbox_run_id' => $onboarding->initial_mailbox_run_id,
            'hints' => [
                'consumer_key_hint' => $onboarding->consumer_key_hint,
                'certificate_fingerprint_sha256' => $onboarding->certificate_fingerprint_sha256,
                'contractor_cnpj_masked' => $onboarding->contractor_cnpj_masked,
                'certificate_valid_to' => $onboarding->certificate_valid_to?->toIso8601String(),
            ],
            'error' => $onboarding->error_code !== null ? [
                'code' => $onboarding->error_code,
                'message' => $onboarding->error_message,
            ] : null,
            'required_actions' => array_values(
                is_array($onboarding->required_actions) ? $onboarding->required_actions : [],
            ),
            'started_at' => $onboarding->started_at?->toIso8601String(),
            'finished_at' => $onboarding->finished_at?->toIso8601String(),
            'created_at' => $onboarding->created_at?->toIso8601String(),
            'updated_at' => $onboarding->updated_at?->toIso8601String(),
        ];
    }
}
