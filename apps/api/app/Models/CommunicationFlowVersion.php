<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'flow_id',
    'version',
    'graph_encrypted',
    'graph_digest',
    'published_at',
    'published_by_membership_id',
])]
class CommunicationFlowVersion extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'graph_encrypted' => 'encrypted:array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlow::class, 'flow_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'published_by_membership_id');
    }
}
