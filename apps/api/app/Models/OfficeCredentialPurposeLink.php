<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OfficeCredentialPurposeLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo de finalidade → credencial canônica (sem material criptográfico).
 * Nunca serializa vault_object_id nem senha.
 */
#[Fillable([
    'office_id',
    'office_credential_id',
    'purpose',
    'status',
    'linked_at',
    'revoked_at',
    'linked_by_user_id',
    'metadata',
])]
class OfficeCredentialPurposeLink extends Model
{
    /** @use HasFactory<OfficeCredentialPurposeLinkFactory> */
    use BelongsToOffice;

    use HasFactory;

    protected static function newFactory(): OfficeCredentialPurposeLinkFactory
    {
        return OfficeCredentialPurposeLinkFactory::new();
    }

    protected function casts(): array
    {
        return [
            'purpose' => OfficeCredentialPurpose::class,
            'status' => CredentialStatus::class,
            'linked_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(OfficeCredential::class, 'office_credential_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === CredentialStatus::Active && $this->revoked_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_credential_id' => $this->office_credential_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'linked_at' => $this->linked_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'active' => $this->isActive(),
        ];
    }
}
