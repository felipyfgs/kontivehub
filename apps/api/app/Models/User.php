<?php

namespace App\Models;

use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Notifications\ResetPasswordNotification;
use App\Services\Platform\PlatformOwnerService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_active', 'password_change_required'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            app(PlatformOwnerService::class)->assertUserMayBeDeleted($user);
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'password_change_required' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function accountActivations(): HasMany
    {
        return $this->hasMany(AccountActivation::class);
    }

    public function selectedOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'selected_office_id');
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class)
            ->using(OfficeMembership::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class);
    }

    public function platformMemberships(): HasMany
    {
        return $this->hasMany(PlatformMembership::class);
    }

    public function updatedFiscalModuleControls(): HasMany
    {
        return $this->hasMany(FiscalModuleControl::class, 'updated_by_user_id');
    }

    /**
     * PLATFORM_ADMIN é autorização global separada — NÃO implica membership de office
     * nem leitura fiscal de qualquer tenant.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->platformMemberships()
            ->where('is_active', true)
            ->where('role', PlatformRole::PlatformAdmin->value)
            ->exists();
    }

    public function activeMembership(): ?OfficeMembership
    {
        return $this->memberships()
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->first();
    }

    public function hasActiveMembershipIn(int $officeId): bool
    {
        return $this->memberships()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    public function roleIn(?Office $office): ?OfficeRole
    {
        if ($office === null) {
            return null;
        }

        $membership = $this->memberships()
            ->where('office_id', $office->id)
            ->where('is_active', true)
            ->first();

        return $membership?->role;
    }

    /**
     * Dados TOTP legados podem existir; o produto não exige mais 2FA.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && $this->two_factor_secret !== null;
    }

    /**
     * TOTP/2FA descontinuado — sempre false.
     */
    public function requiresTwoFactorForAdmin(): bool
    {
        return false;
    }
}
