<?php

namespace App\Models;

use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'office_serpro_authorization_id',
    'environment',
    'version_number',
    'status',
    'author_identity',
    'destination_cnpj',
    'termo_sha256',
    'termo_vault_object_id',
    'signature_mode',
    'valid_from',
    'valid_to',
    'serpro_accepted_at',
    'etag_vault_object_id',
    'token_vault_object_id',
    'token_expires_at',
    'created_by_user_id',
    'segregation_class',
    'metadata',
])]
#[Hidden([
    'termo_vault_object_id',
    'etag_vault_object_id',
    'token_vault_object_id',
])]
class SerproTermVersion extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'serpro_accepted_at' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'segregation_class' => SerproDataSegregationClass::class,
            'metadata' => 'array',
        ];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(OfficeSerproAuthorization::class, 'office_serpro_authorization_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'environment' => $this->environment->value,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'termo_sha256' => $this->termo_sha256,
            'signature_mode' => $this->signature_mode,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'serpro_accepted_at' => $this->serpro_accepted_at?->toIso8601String(),
            'has_termo' => $this->termo_vault_object_id !== null,
            'has_token' => $this->token_vault_object_id !== null,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'segregation_class' => $this->segregation_class?->value,
        ];
    }
}
