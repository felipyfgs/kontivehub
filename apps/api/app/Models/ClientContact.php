<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\ClientContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'name',
    'role',
    'email',
    'phone',
    'is_whatsapp',
    'is_primary',
    'receives_alerts',
    'notes',
    'is_active',
])]
class ClientContact extends Model
{
    /** @use HasFactory<ClientContactFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'is_whatsapp' => 'boolean',
            'is_primary' => 'boolean',
            'receives_alerts' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function communicationIdentityLinks(): HasMany
    {
        return $this->hasMany(CommunicationIdentityLink::class);
    }
}
