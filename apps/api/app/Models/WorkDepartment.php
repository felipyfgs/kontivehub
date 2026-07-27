<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkDepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'name',
    'code',
    'color',
    'is_active',
])]
class WorkDepartment extends Model
{
    /** @use HasFactory<WorkDepartmentFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'work_department_id');
    }

    public function communicationInboxes(): HasMany
    {
        return $this->hasMany(CommunicationInbox::class, 'work_department_id');
    }

    public function communicationConversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'work_department_id');
    }

    protected static function newFactory(): WorkDepartmentFactory
    {
        return WorkDepartmentFactory::new();
    }
}
