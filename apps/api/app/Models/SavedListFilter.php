<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preset nomeado de filtros de lista (personal ou compartilhado no Office).
 *
 * office_id só via CurrentOffice / servidor — nunca autoridade do client.
 */
#[Fillable([
    'office_id',
    'user_id',
    'surface',
    'name',
    'visibility',
    'schema_version',
    'payload',
])]
class SavedListFilter extends Model
{
    use BelongsToOffice;

    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_OFFICE = 'office';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPersonal(): bool
    {
        return $this->visibility === self::VISIBILITY_PERSONAL;
    }

    public function isOfficeShared(): bool
    {
        return $this->visibility === self::VISIBILITY_OFFICE;
    }
}
