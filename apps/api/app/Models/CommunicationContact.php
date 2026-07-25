<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['office_id', 'name', 'is_provisional', 'is_active', 'metadata', 'purged_at'])]
class CommunicationContact extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_provisional' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CommunicationIdentity::class, 'contact_id');
    }
}
