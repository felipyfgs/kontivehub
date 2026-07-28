<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\TenantCredentialPurpose;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantCredentialPurposeLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo de finalidade → certificado (sem material criptográfico).
 * Nunca serializa vault_object_id nem senha.
 */
#[Fillable([
    'tenant_id',
    'tenant_credential_id',
    'purpose',
    'status',
    'linked_at',
    'revoked_at',
    'linked_by_user_id',
    'metadata',
])]
class TenantCredentialPurposeLink extends Model
{
    /** @use HasFactory<TenantCredentialPurposeLinkFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected static function newFactory(): TenantCredentialPurposeLinkFactory
    {
        return TenantCredentialPurposeLinkFactory::new();
    }

    protected function casts(): array
    {
        return [
            'purpose' => TenantCredentialPurpose::class,
            'status' => CredentialStatus::class,
            'linked_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(TenantCredential::class, 'tenant_credential_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === CredentialStatus::Active && $this->revoked_at === null;
    }
}
