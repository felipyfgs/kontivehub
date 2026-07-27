<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'organization_name',
    'onboarding_completed_at',
    'onboarded_by_user_id',
    'primary_tenant_id',
])]
class PlatformSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'onboarding_completed_at' => 'immutable_datetime',
            'onboarded_by_user_id' => 'integer',
            'primary_tenant_id' => 'integer',
        ];
    }

    public function onboardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'onboarded_by_user_id');
    }

    public function primaryTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'primary_tenant_id');
    }
}
