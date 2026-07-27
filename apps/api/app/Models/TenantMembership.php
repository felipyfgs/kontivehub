<?php

namespace App\Models;

use App\Enums\TenantRole;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;
use RuntimeException;

#[Fillable([
    'tenant_id',
    'user_id',
    'role',
    'permission_profile_id',
    'authorization_version',
    'is_active',
    'work_department_id',
])]
class TenantMembership extends Pivot
{
    /** @use HasFactory<TenantMembershipFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'tenant_memberships';

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'permission_profile_id' => 'integer',
            'authorization_version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantMembershipFactory
    {
        return TenantMembershipFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permissionProfile(): BelongsTo
    {
        return $this->belongsTo(TenantPermissionProfile::class, 'permission_profile_id');
    }

    /** Departamento primário operacional (opcional, mesmo escritório). */
    public function workDepartment(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function communicationInboxMemberships(): HasMany
    {
        return $this->hasMany(CommunicationInboxMember::class, 'tenant_membership_id');
    }

    public function assignedCommunicationConversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'assignee_membership_id');
    }

    public function authoredCommunicationMessages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'author_membership_id');
    }

    public function assertCanonicalInvariants(): void
    {
        $role = $this->role;

        if (! $role instanceof TenantRole) {
            throw new InvalidArgumentException('Membership exige um papel tenant válido.');
        }

        if ($role === TenantRole::TenantAdmin) {
            if ($this->permission_profile_id !== null) {
                throw new InvalidArgumentException(
                    'tenant_admin deve ter permission_profile_id nulo.'
                );
            }

            return;
        }

        if ($role === TenantRole::TenantUser) {
            if (! $this->is_active) {
                return;
            }

            if ($this->permission_profile_id === null) {
                throw new InvalidArgumentException(
                    'tenant_user ativo exige permission_profile_id.'
                );
            }

            $profile = $this->permissionProfile;
            if ($profile === null) {
                throw new InvalidArgumentException('Perfil de permissão inexistente.');
            }

            if (! $profile->is_active) {
                throw new RuntimeException('Perfil de permissão inativo.');
            }

            if (! $profile->belongsToTenant((int) $this->tenant_id)) {
                throw new RuntimeException('Perfil de permissão de outro tenant.');
            }
        }
    }

    public function bumpAuthorizationVersion(): void
    {
        $this->authorization_version = max(1, (int) $this->authorization_version) + 1;
        $this->save();
    }
}
