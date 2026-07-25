<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serpro_credential_version_id',
    'action',
    'approver_user_id',
    'approver_role',
    'totp_verified', // legado: true = confirmação humana válida
    'decision',
    'reason',
    'decided_at',
    'context', // confirmation_method / confirmed_at (sem senha/hash)
])]
class SerproCredentialApproval extends Model
{
    protected function casts(): array
    {
        return [
            'totp_verified' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }

    public function credentialVersion(): BelongsTo
    {
        return $this->belongsTo(SerproCredentialVersion::class, 'serpro_credential_version_id');
    }
}
