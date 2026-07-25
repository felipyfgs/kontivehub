<?php

namespace App\Models;

use App\Enums\CteCoverageStatus;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'dfe_document_id', 'access_key', 'number', 'series', 'model',
    'issuer_cnpj', 'issuer_name', 'taker_cnpj', 'taker_name', 'effective_taker_cnpj',
    'sender_cnpj', 'recipient_cnpj', 'expeditor_cnpj', 'expeditor_name',
    'receiver_cnpj', 'receiver_name', 'fiscal_role', 'direction',
    'issued_at', 'total_amount', 'status', 'coverage_status', 'official_status_code',
    'protocol_number', 'is_summary', 'schema_hint', 'schema_version',
])]
class CteDocument extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'fiscal_role' => FiscalRole::class,
            'direction' => DocumentDirection::class,
            'coverage_status' => CteCoverageStatus::class,
            'issued_at' => 'immutable_datetime',
            'total_amount' => 'decimal:2',
            'is_summary' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CteEvent::class);
    }
}
