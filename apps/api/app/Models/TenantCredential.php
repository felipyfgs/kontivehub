<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'status',
    'subject_name', 'holder_cnpj', 'fingerprint_sha256',
    'valid_from', 'valid_to', 'vault_object_id',
    'activated_at', 'superseded_at', 'last_used_at',
    'expires_alert_30', 'expires_alert_7', 'expires_alert_1',
])]
#[Hidden(['vault_object_id'])]
class TenantCredential extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
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

    public function purposeLinks(): HasMany
    {
        return $this->hasMany(TenantCredentialPurposeLink::class, 'tenant_credential_id');
    }
}
