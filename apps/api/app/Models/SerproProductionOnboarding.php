<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use App\Enums\SerproProductionOnboardingStatus;
use App\Enums\SerproProductionOnboardingStep;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\SerproProductionOnboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'actor_user_id',
    'environment',
    'idempotency_key',
    'status',
    'current_step',
    'completed_steps',
    'consent_version',
    'consent_text_sha256',
    'consented_at',
    'correlation_id',
    'serpro_credential_version_id',
    'office_serpro_authorization_id',
    'serpro_rollout_approval_id',
    'initial_mailbox_run_id',
    'consumer_key_hint',
    'certificate_fingerprint_sha256',
    'contractor_cnpj_masked',
    'certificate_valid_to',
    'error_code',
    'error_message',
    'required_actions',
    'metadata',
    'started_at',
    'finished_at',
])]
class SerproProductionOnboarding extends Model
{
    /** @use HasFactory<SerproProductionOnboardingFactory> */
    use BelongsToOffice, HasFactory;

    protected static function newFactory(): SerproProductionOnboardingFactory
    {
        return SerproProductionOnboardingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => SerproProductionOnboardingStatus::class,
            'current_step' => SerproProductionOnboardingStep::class,
            'completed_steps' => 'array',
            'consented_at' => 'immutable_datetime',
            'certificate_valid_to' => 'immutable_datetime',
            'required_actions' => 'array',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function credentialVersion(): BelongsTo
    {
        return $this->belongsTo(SerproCredentialVersion::class, 'serpro_credential_version_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(OfficeSerproAuthorization::class, 'office_serpro_authorization_id');
    }

    public function initialMailboxRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'initial_mailbox_run_id');
    }

    public function markStepCompleted(SerproProductionOnboardingStep $step): void
    {
        $steps = is_array($this->completed_steps) ? $this->completed_steps : [];
        if (! in_array($step->value, $steps, true)) {
            $steps[] = $step->value;
        }

        $this->completed_steps = $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'environment' => $this->environment?->value,
            'status' => $this->status?->value,
            'current_step' => $this->current_step?->value,
            'completed_steps' => array_values(is_array($this->completed_steps) ? $this->completed_steps : []),
            'correlation_id' => $this->correlation_id,
            'consent' => [
                'version' => $this->consent_version,
                'text_sha256' => $this->consent_text_sha256,
                'consented_at' => $this->consented_at?->toIso8601String(),
                'actor_user_id' => $this->actor_user_id,
            ],
            'credential_version_id' => $this->serpro_credential_version_id,
            'authorization_id' => $this->office_serpro_authorization_id,
            'rollout_approval_id' => $this->serpro_rollout_approval_id,
            'initial_mailbox_run_id' => $this->initial_mailbox_run_id,
            'hints' => [
                'consumer_key_hint' => $this->consumer_key_hint,
                'certificate_fingerprint_sha256' => $this->certificate_fingerprint_sha256,
                'contractor_cnpj_masked' => $this->contractor_cnpj_masked,
                'certificate_valid_to' => $this->certificate_valid_to?->toIso8601String(),
            ],
            'error' => $this->error_code !== null ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ] : null,
            'required_actions' => array_values(is_array($this->required_actions) ? $this->required_actions : []),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
