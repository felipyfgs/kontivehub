<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'office_fiscal_identity_id', 'purpose', 'status',
    'subject_name', 'holder_cnpj', 'fingerprint_sha256',
    'valid_from', 'valid_to', 'vault_object_id',
    'activated_at', 'superseded_at', 'last_used_at',
    'expires_alert_30', 'expires_alert_7', 'expires_alert_1',
])]
#[Hidden(['vault_object_id'])]
class OfficeCredential extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => OfficeCredentialPurpose::class,
            'status' => CredentialStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'expires_alert_30' => 'boolean',
            'expires_alert_7' => 'boolean',
            'expires_alert_1' => 'boolean',
        ];
    }

    public function fiscalIdentity(): BelongsTo
    {
        return $this->belongsTo(OfficeFiscalIdentity::class, 'office_fiscal_identity_id');
    }

    public function purposeLinks(): HasMany
    {
        return $this->hasMany(OfficeCredentialPurposeLink::class, 'office_credential_id');
    }

    public function isCanonical(): bool
    {
        return $this->purpose === OfficeCredentialPurpose::CanonicalECnpjA1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_fiscal_identity_id' => $this->office_fiscal_identity_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'subject_name' => $this->subject_name,
            'holder_cnpj' => $this->holder_cnpj,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_alert_30' => $this->expires_alert_30,
            'expires_alert_7' => $this->expires_alert_7,
            'expires_alert_1' => $this->expires_alert_1,
            'is_canonical' => $this->isCanonical(),
        ];
    }
}
