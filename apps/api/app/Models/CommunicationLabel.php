<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['office_id', 'name', 'color'])]
class CommunicationLabel extends Model
{
    use BelongsToOffice;

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(CommunicationConversation::class, 'communication_conversation_labels', 'label_id', 'conversation_id')
            ->withPivot(['office_id', 'assigned_by_membership_id'])
            ->withTimestamps();
    }
}
