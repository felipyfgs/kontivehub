<?php

namespace App\Models;

use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OfficeSerproOnboardingStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'environment',
    'status',
    'idempotency_key',
    'last_step',
    'actionable_code',
    'actionable_message',
    'technical_code',
    'technical_message',
    'correlation_id',
    'ready_at',
    'provisioning_started_at',
    'authorized_at',
    'last_transition_at',
    'metadata',
])]
class OfficeSerproOnboardingState extends Model
{
    /** @use HasFactory<OfficeSerproOnboardingStateFactory> */
    use BelongsToOffice;

    use HasFactory;

    protected static function newFactory(): OfficeSerproOnboardingStateFactory
    {
        return OfficeSerproOnboardingStateFactory::new();
    }

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => OfficeSerproOnboardingStatus::class,
            'ready_at' => 'immutable_datetime',
            'provisioning_started_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'last_transition_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Tenant-facing projection: only actionable state + sanitized correlation.
     *
     * @return array<string, mixed>
     */
    public function toTenantArray(): array
    {
        return [
            'status' => $this->status->value,
            'last_step' => $this->last_step,
            'actionable' => $this->actionable_code !== null
                ? [
                    'code' => $this->actionable_code,
                    'message' => $this->actionable_message,
                ]
                : null,
            'correlation_id' => $this->correlation_id,
            'ready_at' => $this->ready_at?->toIso8601String(),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'initial_collection_queued_at' => is_array($this->metadata)
                ? ($this->metadata['initial_collection_queued_at'] ?? null)
                : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Platform diagnosis (may include technical codes; never secrets).
     *
     * @return array<string, mixed>
     */
    public function toPlatformArray(): array
    {
        return array_merge($this->toTenantArray(), [
            'office_id' => $this->office_id,
            'environment' => $this->environment->value,
            'idempotency_key' => $this->idempotency_key,
            'technical' => $this->technical_code !== null
                ? [
                    'code' => $this->technical_code,
                    'message' => $this->technical_message,
                ]
                : null,
            'provisioning_started_at' => $this->provisioning_started_at?->toIso8601String(),
            'last_transition_at' => $this->last_transition_at?->toIso8601String(),
            'metadata' => $this->sanitizedMetadata(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizedMetadata(): ?array
    {
        $meta = is_array($this->metadata) ? $this->metadata : null;
        if ($meta === null) {
            return null;
        }

        foreach (['token', 'password', 'pfx', 'secret', 'xml', 'termo_xml', 'bearer'] as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }
}
