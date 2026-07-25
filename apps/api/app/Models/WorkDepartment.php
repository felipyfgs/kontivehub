<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\WorkDepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'name',
    'code',
    'color',
    'is_active',
])]
class WorkDepartment extends Model
{
    /** @use HasFactory<WorkDepartmentFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class, 'work_department_id');
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
