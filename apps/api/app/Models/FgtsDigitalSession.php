<?php

namespace App\Models;

use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id', 'client_id', 'representation_id', 'credential_source', 'credential_fingerprint',
    'profile_type', 'target_identifier_hash', 'contract_version', 'status', 'vault_object_id',
    'expires_at', 'last_used_at', 'metadata',
])]
#[Hidden(['vault_object_id', 'credential_fingerprint', 'target_identifier_hash', 'metadata'])]
class FgtsDigitalSession extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'credential_source' => FgtsDigitalCredentialSource::class,
            'status' => FgtsDigitalSessionStatus::class,
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === FgtsDigitalSessionStatus::Ready
            && $this->expires_at->isFuture()
            && $this->vault_object_id !== null;
    }
}
