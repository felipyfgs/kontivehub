<?php

namespace App\Models;

use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentDirection;
use App\Enums\DocumentPurpose;
use App\Enums\FiscalRole;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'dfe_document_id', 'access_key', 'number', 'series', 'model',
    'issuer_cnpj', 'issuer_name', 'recipient_cnpj', 'recipient_name',
    'fiscal_role', 'direction', 'purpose', 'acquisition_source',
    'issued_at', 'total_amount', 'status', 'official_status_code',
    'is_summary', 'manifestation_status', 'schema_hint',
])]
class NfeDocument extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'fiscal_role' => FiscalRole::class,
            'direction' => DocumentDirection::class,
            'purpose' => DocumentPurpose::class,
            'acquisition_source' => DocumentAcquisitionSource::class,
            'issued_at' => 'immutable_datetime',
            'total_amount' => 'decimal:2',
            'is_summary' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }
}
