<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'status',
    'subject_name',
    'holder_cnpj',
    'fingerprint_sha256',
    'valid_from',
    'valid_to',
    'vault_object_id',
    'activated_at',
    'superseded_at',
    'expires_alert_30',
    'expires_alert_7',
    'expires_alert_1',
])]
#[Hidden(['vault_object_id'])]
class ClientCredential extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'expires_alert_30' => 'boolean',
            'expires_alert_7' => 'boolean',
            'expires_alert_1' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'status' => $this->status->value,
            'subject_name' => $this->subject_name,
            'holder_cnpj' => $this->holder_cnpj,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'expires_alert_30' => $this->expires_alert_30,
            'expires_alert_7' => $this->expires_alert_7,
            'expires_alert_1' => $this->expires_alert_1,
        ];
    }
}
