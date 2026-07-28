<?php

namespace App\Http\Resources;

use App\Enums\SerproApprovalPolicy;
use App\Enums\SerproEnvironment;
use App\Models\SerproRolloutApproval;
use App\Support\LogSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproRolloutApproval */
final class SerproRolloutApprovalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproRolloutApproval $approval */
        $approval = $this->resource;
        $policy = $approval->policy();

        return [
            'id' => $approval->id,
            'subject_type' => $approval->subject_type,
            'subject_id' => $approval->subject_id,
            'action' => $approval->action,
            'approval_policy' => $policy->value,
            'environment' => $approval->environment instanceof SerproEnvironment
                ? $approval->environment->value
                : (string) $approval->environment,
            'tenant_id' => $approval->tenant_id,
            'status' => $approval->status,
            'reason' => $approval->reason,
            'confirmation_phrase' => $approval->confirmation_phrase,
            'expected_confirmation_phrase' => $policy === SerproApprovalPolicy::OwnerConfirmation
                ? ($approval->confirmation_phrase ?? 'CONFIRMO-'.strtoupper((string) $approval->action))
                : null,
            'requested_by_user_id' => $approval->requested_by_user_id,
            'first_approver_user_id' => $approval->first_approver_user_id,
            'second_approver_user_id' => $approval->second_approver_user_id,
            'first_approved_at' => $approval->first_approved_at?->toIso8601String(),
            'second_approved_at' => $approval->second_approved_at?->toIso8601String(),
            'executed_at' => $approval->executed_at?->toIso8601String(),
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'change_window_start' => $approval->change_window_start?->toIso8601String(),
            'change_window_end' => $approval->change_window_end?->toIso8601String(),
            'fully_approved' => $approval->isFullyApproved(),
            'context' => is_array($approval->context)
                ? LogSanitizer::redact($approval->context)
                : null,
        ];
    }
}
