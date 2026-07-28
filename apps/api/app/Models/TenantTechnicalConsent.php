<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantTechnicalConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consentimento técnico versionado (uso do certificado e finalidades apresentadas).
 * Histórico append-only; revogação marca revoked_at.
 */
#[Fillable([
    'tenant_id',
    'version_code',
    'purposes_presented',
    'actor_user_id',
    'consented_at',
    'revoked_at',
    'payload_sha256',
    'metadata',
])]
class TenantTechnicalConsent extends Model
{
    /** Versão vigente inicial das finalidades de certificado + Termo + autXML. */
    public const VERSION_CERTIFICATE_V1 = 'certificate.v1';

    /** @use HasFactory<TenantTechnicalConsentFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected static function newFactory(): TenantTechnicalConsentFactory
    {
        return TenantTechnicalConsentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'purposes_presented' => 'array',
            'consented_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
