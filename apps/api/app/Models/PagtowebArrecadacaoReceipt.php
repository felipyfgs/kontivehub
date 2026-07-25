<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadados públicos mínimos; PDF, digest e referência do cofre permanecem privados. */
#[Fillable([
    'office_id',
    'client_id',
    'receipt_vault_object_id',
    'receipt_sha256',
    'receipt_mime_type',
    'receipt_byte_size',
    'source_provenance',
    'observed_at',
])]
#[Hidden(['receipt_vault_object_id', 'receipt_sha256'])]
class PagtowebArrecadacaoReceipt extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'receipt_byte_size' => 'integer',
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
            'mime_type' => $this->receipt_mime_type,
            'byte_size' => $this->receipt_byte_size,
            'source_provenance' => $this->source_provenance?->value,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
