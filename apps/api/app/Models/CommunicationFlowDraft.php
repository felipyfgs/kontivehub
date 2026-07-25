<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'flow_id',
    'graph_encrypted',
    'graph_digest',
    'lock_version',
    'updated_by_membership_id',
])]
class CommunicationFlowDraft extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'graph_encrypted' => 'encrypted:array',
            'lock_version' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlow::class, 'flow_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'updated_by_membership_id');
    }
}
