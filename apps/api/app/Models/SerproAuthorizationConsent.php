<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'tenant_serpro_authorization_id',
    'consent_type',
    'version_code',
    'actor_user_id',
    'consented_at',
    'revoked_at',
    'payload_sha256',
    'metadata',
])]
class SerproAuthorizationConsent extends Model
{
    use BelongsToTenant;

    public const TYPE_CERTIFICATE_SIGN = 'CERTIFICATE_SIGN';

    public const TYPE_PRODUCTION_ONBOARDING = 'PRODUCTION_ONBOARDING';

    public const VERSION_CERTIFICATE_SIGN_V1 = 'certificate-sign.v1';

    public const VERSION_PRODUCTION_ONBOARDING_V1 = 'serpro-prod-onboarding.v1';

    protected function casts(): array
    {
        return [
            'consented_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TenantSerproAuthorization::class, 'tenant_serpro_authorization_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'consent_type' => $this->consent_type,
            'version_code' => $this->version_code,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'payload_sha256' => $this->payload_sha256,
            'active' => $this->isActive(),
        ];
    }
}
