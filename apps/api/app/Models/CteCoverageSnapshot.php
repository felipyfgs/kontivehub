<?php

namespace App\Models;

use App\Enums\CteCoverageStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'client_id', 'period', 'status',
    'documents_count', 'original_count', 'autxml_redacted_count', 'pending_import_count',
    'metadata', 'computed_at',
])]
class CteCoverageSnapshot extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => CteCoverageStatus::class,
            'documents_count' => 'integer',
            'original_count' => 'integer',
            'autxml_redacted_count' => 'integer',
            'pending_import_count' => 'integer',
            'metadata' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
