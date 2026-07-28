<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use App\Enums\SerproProductionOnboardingStatus;
use App\Enums\SerproProductionOnboardingStep;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SerproProductionOnboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
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
    'tenant_serpro_authorization_id',
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
    use BelongsToTenant, HasFactory;

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
        return $this->belongsTo(TenantSerproAuthorization::class, 'tenant_serpro_authorization_id');
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
}
