<?php

namespace App\Models;

use App\Enums\TenantLifecycleStatus;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'is_active', 'communication_enabled', 'lifecycle_status', 'serpro_segregation_class', 'deadline_timezone', 'timezone'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(static function (Tenant $tenant): void {
            app(SystemTenantPermissionProfiles::class)->ensure($tenant);
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'communication_enabled' => 'boolean',
            'lifecycle_status' => TenantLifecycleStatus::class,
        ];
    }

    public function isPendingActivation(): bool
    {
        return $this->lifecycle_status === TenantLifecycleStatus::PendingActivation;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'tenant_memberships',
            'tenant_id',
            'user_id',
        )
            ->using(TenantMembership::class)
            ->withPivot([
                'role',
                'permission_profile_id',
                'authorization_version',
                'is_active',
                'work_department_id',
            ])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function permissionProfiles(): HasMany
    {
        return $this->hasMany(TenantPermissionProfile::class);
    }

    public function isOperational(): bool
    {
        $status = $this->lifecycle_status;

        return $status instanceof TenantLifecycleStatus
            ? $status->isOperational()
            : (bool) $this->is_active;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    public function institutionalProfile(): HasOne
    {
        return $this->hasOne(TenantInstitutionalProfile::class);
    }

    public function technicalConsents(): HasMany
    {
        return $this->hasMany(TenantTechnicalConsent::class);
    }

    public function credentialPurposeLinks(): HasMany
    {
        return $this->hasMany(TenantCredentialPurposeLink::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(TenantCredential::class);
    }

    public function serproOnboardingStates(): HasMany
    {
        return $this->hasMany(TenantSerproOnboardingState::class);
    }

    public function fiscalModuleControls(): HasMany
    {
        return $this->hasMany(FiscalModuleControl::class);
    }

    public function accountActivations(): HasMany
    {
        return $this->hasMany(AccountActivation::class);
    }

    public function communicationInboxes(): HasMany
    {
        return $this->hasMany(CommunicationInbox::class);
    }

    public function communicationContacts(): HasMany
    {
        return $this->hasMany(CommunicationContact::class);
    }

    public function communicationAutomationPolicies(): HasMany
    {
        return $this->hasMany(CommunicationAutomationPolicy::class);
    }
}
