<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'identity_id',
    'client_id',
    'client_contact_id',
    'is_primary',
    'receives_automatic',
])]
class CommunicationIdentityLink extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'receives_automatic' => 'boolean',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'identity_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientContact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class);
    }
}
