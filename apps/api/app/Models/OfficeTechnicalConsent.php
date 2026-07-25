<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OfficeTechnicalConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consentimento técnico versionado (uso do A1 e finalidades apresentadas).
 * Histórico append-only; revogação marca revoked_at.
 */
#[Fillable([
    'office_id',
    'version_code',
    'purposes_presented',
    'actor_user_id',
    'consented_at',
    'revoked_at',
    'payload_sha256',
    'metadata',
])]
class OfficeTechnicalConsent extends Model
{
    /** Versão vigente inicial das finalidades de A1 canônico + Termo + autXML. */
    public const VERSION_UNIFIED_A1_V1 = 'unified-a1.v1';

    /** @use HasFactory<OfficeTechnicalConsentFactory> */
    use BelongsToOffice;

    use HasFactory;

    protected static function newFactory(): OfficeTechnicalConsentFactory
    {
        return OfficeTechnicalConsentFactory::new();
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

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'version_code' => $this->version_code,
            'purposes_presented' => $this->purposes_presented,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'payload_sha256' => $this->payload_sha256,
            'active' => $this->isActive(),
        ];
    }
}
