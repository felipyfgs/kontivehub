<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'dfe_document_id', 'access_key', 'event_type', 'event_at', 'status',
])]
class NfseEvent extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'event_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }
}
