<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['permission_profile_id', 'permission_key'])]
class TenantPermissionProfilePermission extends Model
{
    protected $table = 'tenant_permission_profile_permissions';

    protected function casts(): array
    {
        return [
            'permission_profile_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TenantPermissionProfile::class, 'permission_profile_id');
    }
}
