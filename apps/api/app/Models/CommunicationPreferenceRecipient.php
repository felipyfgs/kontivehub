<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'preference_id', 'identity_id'])]
class CommunicationPreferenceRecipient extends Model
{
    use BelongsToTenant;

    public function preference(): BelongsTo
    {
        return $this->belongsTo(ClientCommunicationPreference::class, 'preference_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'identity_id');
    }
}
