<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadados públicos mínimos do certificado; bytes e identificador do cofre são privados. */
#[Fillable([
    'office_id',
    'client_id',
    'contributor_cnpj',
    'certificate_vault_object_id',
    'certificate_sha256',
    'certificate_mime_type',
    'certificate_byte_size',
    'source_provenance',
    'observed_at',
])]
#[Hidden(['certificate_vault_object_id', 'certificate_sha256', 'contributor_cnpj'])]
class CcmeiIssuedCertificate extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'certificate_byte_size' => 'integer',
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'mime_type' => $this->certificate_mime_type,
            'byte_size' => $this->certificate_byte_size,
            'source_provenance' => $this->source_provenance?->value,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
