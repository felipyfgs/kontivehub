<?php

namespace App\Models;

use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalRepresentationStatus;
use App\Models\Concerns\BelongsToOffice;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'client_id', 'office_credential_id', 'credential_source', 'profile_type',
    'target_identifier_hash', 'status', 'valid_from', 'valid_to', 'verified_by', 'verified_at', 'metadata',
])]
#[Hidden(['target_identifier_hash', 'metadata'])]
class FgtsDigitalRepresentation extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'credential_source' => FgtsDigitalCredentialSource::class,
            'status' => FgtsDigitalRepresentationStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function officeCredential(): BelongsTo
    {
        return $this->belongsTo(OfficeCredential::class);
    }

    public function isUsable(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === FgtsDigitalRepresentationStatus::Active
            && ($this->valid_from === null || $this->valid_from->lte($at))
            && ($this->valid_to === null || $this->valid_to->gt($at));
    }
}
