<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'name', 'color'])]
class CommunicationLabel extends Model
{
    use BelongsToTenant;

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(CommunicationConversation::class, 'communication_conversation_labels', 'label_id', 'conversation_id')
            ->withPivot(['tenant_id', 'assigned_by_membership_id'])
            ->withTimestamps();
    }
}
