<?php

namespace App\Models;

use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalSessionStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'office_id', 'client_id', 'representation_id', 'credential_source', 'credential_fingerprint',
    'profile_type', 'target_identifier_hash', 'contract_version', 'status', 'vault_object_id',
    'expires_at', 'last_used_at', 'metadata',
])]
#[Hidden(['vault_object_id', 'credential_fingerprint', 'target_identifier_hash', 'metadata'])]
class FgtsDigitalSession extends Model
{
    use BelongsToOffice;

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

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'credential_source' => $this->credential_source->value,
            'profile_type' => $this->profile_type,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
