<?php

namespace App\Services\Platform;

use App\Enums\PlatformRole;
use App\Models\Office;
use App\Models\PlatformMembership;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitialOnboardingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformOwnerService $owners,
    ) {}

    public function available(): bool
    {
        return $this->configurationIsValid() && $this->databaseIsPristine();
    }

    /**
     * @return array{user: User, settings: PlatformSetting}
     *
     * @throws InitialOnboardingException
     */
    public function complete(
        string $organizationName,
        string $email,
        string $password,
        string $providedToken,
    ): array {
        $this->assertAuthorized($providedToken);

        try {
            $result = DB::transaction(function () use ($organizationName, $email, $password): array {
                if (! $this->databaseIsPristine()) {
                    throw InitialOnboardingException::unavailable();
                }

                $settings = PlatformSetting::query()->create([
                    'id' => PlatformSetting::SINGLETON_ID,
                    'organization_name' => trim($organizationName),
                    'onboarding_completed_at' => null,
                    'onboarded_by_user_id' => null,
                ]);

                // A linha singleton foi reivindicada; nenhuma outra conclusão pode prosseguir.
                if (User::query()->exists()
                    || Office::query()->exists()
                    || PlatformMembership::query()->exists()) {
                    throw InitialOnboardingException::unavailable();
                }

                $user = new User;
                $user->forceFill([
                    'name' => 'Administrador da plataforma',
                    'email' => Str::lower(trim($email)),
                    // Cast `hashed` aplica o hash — não pré-hashear.
                    'password' => $password,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'password_change_required' => false,
                    'selected_office_id' => null,
                ]);
                $user->save();

                try {
                    $this->owners->createOwner($user, isActive: true, defaultOfficeId: null);
                } catch (PlatformOwnerException) {
                    throw InitialOnboardingException::unavailable();
                }

                $settings->forceFill([
                    'onboarding_completed_at' => now(),
                    'onboarded_by_user_id' => $user->id,
                ])->save();

                return ['user' => $user, 'settings' => $settings];
            }, 3);
        } catch (QueryException $e) {
            if (PlatformSetting::query()->whereKey(PlatformSetting::SINGLETON_ID)->exists()) {
                throw InitialOnboardingException::unavailable();
            }

            throw $e;
        } catch (PlatformOwnerException) {
            throw InitialOnboardingException::unavailable();
        }

        $this->audit->record(
            action: 'platform.initial_onboarding_completed',
            result: 'SUCCESS',
            subject: $result['user'],
            context: [
                'organization_name' => $result['settings']->organization_name,
                'platform_role' => PlatformRole::PlatformAdmin->value,
                'office_created' => false,
                'office_membership_created' => false,
            ],
            userId: $result['user']->id,
        );

        return $result;
    }

    private function configurationIsValid(): bool
    {
        $token = (string) config('onboarding.token', '');

        return (bool) config('onboarding.enabled', false)
            && strlen($token) >= 32;
    }

    private function databaseIsPristine(): bool
    {
        return ! PlatformSetting::query()->exists()
            && ! User::query()->exists()
            && ! Office::query()->exists()
            && ! PlatformMembership::query()->exists();
    }

    /** @throws InitialOnboardingException */
    private function assertAuthorized(string $providedToken): void
    {
        if (! $this->configurationIsValid()) {
            throw InitialOnboardingException::unauthorized();
        }

        $expected = (string) config('onboarding.token');
        if ($providedToken === '' || ! hash_equals($expected, $providedToken)) {
            throw InitialOnboardingException::unauthorized();
        }
    }
}
