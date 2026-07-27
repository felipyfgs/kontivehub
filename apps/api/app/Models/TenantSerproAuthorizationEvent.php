<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'tenant_serpro_authorization_id',
    'from_status',
    'to_status',
    'event',
    'message',
    'actor_user_id',
    'context',
    'created_at',
])]
class TenantSerproAuthorizationEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TenantSerproAuthorization::class, 'tenant_serpro_authorization_id');
    }
}
