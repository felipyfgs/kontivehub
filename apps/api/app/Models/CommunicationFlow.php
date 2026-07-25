<?php

namespace App\Models;

use App\Enums\Communication\FlowStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'office_id',
    'name',
    'status',
    'lock_version',
    'created_by_membership_id',
])]
class CommunicationFlow extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => FlowStatus::class,
            'lock_version' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'created_by_membership_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(CommunicationFlowDraft::class, 'flow_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommunicationFlowVersion::class, 'flow_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(CommunicationFlowInboxBinding::class, 'flow_id');
    }
}
