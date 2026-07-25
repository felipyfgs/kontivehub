<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_id', 'inbox_id', 'office_membership_id', 'is_active'])]
class CommunicationInboxMember extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'office_membership_id');
    }
}
