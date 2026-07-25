<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serpro_credential_version_id',
    'environment',
    'fingerprint_sha256',
    'success',
    'tested_at',
    'expires_at',
    'http_status',
    'sanitized_message',
    'actor_user_id',
    'correlation_id',
    'invalidated',
    'invalidated_at',
    'invalidation_reason',
    'metadata',
])]
class SerproCredentialConnectionEvidence extends Model
{
    protected $table = 'serpro_credential_connection_evidences';

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'success' => 'boolean',
            'tested_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'invalidated' => 'boolean',
            'invalidated_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function credentialVersion(): BelongsTo
    {
        return $this->belongsTo(SerproCredentialVersion::class, 'serpro_credential_version_id');
    }

    public function isValidFor(
        SerproCredentialVersion $version,
        ?\DateTimeInterface $at = null,
    ): bool {
        if (! $this->success || $this->invalidated) {
            return false;
        }

        if ((int) $this->serpro_credential_version_id !== (int) $version->id) {
            return false;
        }

        $env = $version->environment instanceof SerproEnvironment
            ? $version->environment->value
            : (string) $version->environment;

        if ($this->environment->value !== $env) {
            return false;
        }

        if (! hash_equals((string) $this->fingerprint_sha256, (string) $version->fingerprint_sha256)) {
            return false;
        }

        $at = $at ?? now();

        return $this->expires_at !== null && $this->expires_at->isAfter($at);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'serpro_credential_version_id' => $this->serpro_credential_version_id,
            'environment' => $this->environment->value,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'success' => (bool) $this->success,
            'tested_at' => $this->tested_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'http_status' => $this->http_status,
            'sanitized_message' => $this->sanitized_message,
            'invalidated' => (bool) $this->invalidated,
            'invalidated_at' => $this->invalidated_at?->toIso8601String(),
            'invalidation_reason' => $this->invalidation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
