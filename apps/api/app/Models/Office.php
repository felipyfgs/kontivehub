<?php

namespace App\Models;

use App\Enums\OfficeLifecycleStatus;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'is_active', 'communication_enabled', 'lifecycle_status', 'serpro_segregation_class', 'deadline_timezone', 'timezone'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'communication_enabled' => 'boolean',
            'lifecycle_status' => OfficeLifecycleStatus::class,
        ];
    }

    public function isPendingActivation(): bool
    {
        return $this->lifecycle_status === OfficeLifecycleStatus::PendingActivation;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OfficeMembership::class)
            ->withPivot([
                'role',
                'tenant_role',
                'permission_profile_id',
                'authorization_version',
                'is_active',
                'work_department_id',
            ])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class);
    }

    public function permissionProfiles(): HasMany
    {
        return $this->hasMany(TenantPermissionProfile::class);
    }

    public function isOperational(): bool
    {
        $status = $this->lifecycle_status;

        return $status instanceof OfficeLifecycleStatus
            ? $status->isOperational()
            : (bool) $this->is_active;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(OfficeSubscription::class);
    }

    public function institutionalProfile(): HasOne
    {
        return $this->hasOne(OfficeInstitutionalProfile::class);
    }

    public function technicalConsents(): HasMany
    {
        return $this->hasMany(OfficeTechnicalConsent::class);
    }

    public function credentialPurposeLinks(): HasMany
    {
        return $this->hasMany(OfficeCredentialPurposeLink::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(OfficeCredential::class);
    }

    public function serproOnboardingStates(): HasMany
    {
        return $this->hasMany(OfficeSerproOnboardingState::class);
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
