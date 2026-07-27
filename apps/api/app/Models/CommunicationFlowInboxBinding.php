<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'flow_id',
    'inbox_id',
    'published_version_id',
    'enabled',
    'lock_version',
])]
class CommunicationFlowInboxBinding extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlow::class, 'flow_id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlowVersion::class, 'published_version_id');
    }
}
