<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'contact_id',
    'channel',
    'address_encrypted',
    'address_hash',
    'address_masked',
    'is_active',
    'last_seen_at',
    'purged_at',
])]
class CommunicationIdentity extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'address_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CommunicationContact::class, 'contact_id');
    }

    public function clientLinks(): HasMany
    {
        return $this->hasMany(CommunicationIdentityLink::class, 'identity_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'identity_id');
    }
}
