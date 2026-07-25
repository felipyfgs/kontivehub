<?php

namespace App\Models;

use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'dfe_document_id', 'establishment_id', 'nsu', 'environment', 'channel',
    'fiscal_role', 'direction',
])]
class DocumentInterest extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'nsu' => 'integer',
            'fiscal_role' => FiscalRole::class,
            'direction' => DocumentDirection::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
