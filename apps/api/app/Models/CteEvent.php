<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'dfe_document_id', 'cte_document_id', 'access_key',
    'event_type', 'sequence', 'protocol_number', 'cstat', 'event_at', 'status',
])]
class CteEvent extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    public function cteDocument(): BelongsTo
    {
        return $this->belongsTo(CteDocument::class);
    }
}
