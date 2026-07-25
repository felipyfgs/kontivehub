<?php

namespace Database\Seeders;

use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Fixture local/testing do Proprietário (PLATFORM_ADMIN) exclusivamente global.
 * Não cria OfficeMembership, AccountActivation nem consome seats do plano.
 * Recusa qualquer vínculo global prévio de outro usuário.
 */
class PlatformAdminDemoSeeder extends Seeder
{
    public const NAME = 'Admin Plataforma Demo';

    public const EMAIL = 'plataforma@example.com';

    public const PASSWORD = 'password';

    public const OFFICE_SLUG = 'plataforma';

    /** Nome genérico do office plataforma no seed de desenvolvimento (não é piloto real). */
    public const OFFICE_NAME = 'Plataforma Demo';

    public function run(): void
    {
        $productionAllowed = filter_var(env('PILOT_SEED_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOL);

        if (! app()->environment(['local', 'testing']) && ! $productionAllowed) {
            throw new LogicException(
                'PlatformAdminDemoSeeder só pode ser executado em local/testing.',
            );
        }

        $office = Office::query()
            ->where('slug', self::OFFICE_SLUG)
            ->where('is_active', true)
            ->first();

        if ($office === null) {
            throw new RuntimeException(
                'Office da Plataforma ativo (slug "'.self::OFFICE_SLUG.'") é obrigatório. Execute o DatabaseSeeder completo antes do PlatformAdminDemoSeeder.',
            );
        }

        /** @var PlatformOwnerService $owners */
        $owners = app(PlatformOwnerService::class);

        DB::transaction(function () use ($office, $owners): void {
            $user = User::query()->where('email', self::EMAIL)->first();

            if ($user === null) {
                // Piloto (LocalSerproSmokeSeeder) pode ter promovido Felipe a titular.
                if ($owners->exists()) {
                    return;
                }
                $this->assertNoForeignOwner($owners);
                $this->createFixture($office, $owners);

                return;
            }

            // E-mail demo existe mas o titular já é outro (ex.: felipe@example.com).
            $currentOwner = $owners->findMembership();
            if ($currentOwner !== null && (int) $currentOwner->user_id !== (int) $user->id) {
                $user->forceFill(['is_active' => false])->save();

                return;
            }

            $this->assertCompatibleGlobalFixture($user);
            $this->ensurePlatformMembership($user, $office, $owners);
        });
    }

    private function assertNoForeignOwner(PlatformOwnerService $owners): void
    {
        $existing = $owners->findMembership();
        if ($existing !== null) {
            throw new RuntimeException(
                'Já existe um Proprietário (PLATFORM_ADMIN). O seed não cria um segundo vínculo global.',
            );
        }
    }

    private function createFixture(Office $office, PlatformOwnerService $owners): void
    {
        $user = new User;
        $user->forceFill([
            'name' => self::NAME,
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'is_active' => true,
            'password_change_required' => false,
            'selected_office_id' => null,
        ]);
        $user->save();

        try {
            $owners->createOwner($user, isActive: true, defaultOfficeId: $office->id);
        } catch (PlatformOwnerException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function assertCompatibleGlobalFixture(User $user): void
    {
        // OfficeMembership no office plataforma é permitido (dev dual-account e piloto).
        // O que bloqueia é AccountActivation pendente ou ausência de PLATFORM_ADMIN.

        if (AccountActivation::query()->where('user_id', $user->id)->exists()) {
            throw new RuntimeException(
                'E-mail reservado '.self::EMAIL.' possui AccountActivation; fixture demo exige conta global pronta sem onboarding pendente.',
            );
        }

        $membership = PlatformMembership::query()
            ->where('user_id', $user->id)
            ->where('role', 'PLATFORM_ADMIN')
            ->first();

        if ($membership === null) {
            throw new RuntimeException(
                'E-mail reservado '.self::EMAIL.' existe sem PlatformMembership PLATFORM_ADMIN compatível; o seed não concede privilégio automaticamente.',
            );
        }

        $foreignOwner = PlatformMembership::query()
            ->where('role', 'PLATFORM_ADMIN')
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($foreignOwner) {
            throw new RuntimeException(
                'Há outro PLATFORM_ADMIN além da fixture '.self::EMAIL.'; o seed não concede privilégio adicional.',
            );
        }

        $otherGrants = PlatformMembership::query()
            ->where('user_id', $user->id)
            ->where('role', '!=', 'PLATFORM_ADMIN')
            ->exists();

        if ($otherGrants) {
            throw new RuntimeException(
                'E-mail reservado '.self::EMAIL.' possui grant de plataforma incompatível com a fixture exclusivamente global.',
            );
        }
    }

    private function ensurePlatformMembership(User $user, Office $office, PlatformOwnerService $owners): void
    {
        if ($user->selected_office_id !== null) {
            $user->forceFill(['selected_office_id' => null])->save();
        }

        $membership = PlatformMembership::query()
            ->where('user_id', $user->id)
            ->where('role', 'PLATFORM_ADMIN')
            ->first();

        if ($membership === null) {
            // assertCompatibleGlobalFixture já deveria ter falhado; defesa em profundidade.
            throw new RuntimeException(
                'E-mail reservado '.self::EMAIL.' existe sem PlatformMembership PLATFORM_ADMIN compatível; o seed não concede privilégio automaticamente.',
            );
        }

        $membership->forceFill([
            'is_active' => true,
            'default_office_id' => $office->id,
        ])->save();
    }
}
