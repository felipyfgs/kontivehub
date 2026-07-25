<?php

namespace App\Services\Platform;

use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\PlatformMembership;
use App\Models\PlatformSetting;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Preflight + backfill idempotente do RBAC multi-tenant canônico.
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D13
 */
final class MultitenantRbacMigrateService
{
    /**
     * @return array{
     *     mode: string,
     *     blocked: bool,
     *     blockers: list<string>,
     *     counts: array<string, int|array<string, int>>,
     *     primary_office_id: int|null,
     *     offices: list<array<string, mixed>>,
     *     applied: bool,
     *     sessions_revoked: int
     * }
     */
    public function run(bool $apply, ?int $primaryOfficeId = null, bool $confirm = false): array
    {
        $report = $this->inventory($primaryOfficeId);
        $report['mode'] = $apply ? 'apply' : 'dry-run';
        $report['applied'] = false;
        $report['sessions_revoked'] = 0;

        if (! $apply) {
            return $report;
        }

        if ($report['blocked']) {
            return $report;
        }

        if (! $confirm) {
            $report['blocked'] = true;
            $report['blockers'][] = 'apply exige --confirm';

            return $report;
        }

        if ($primaryOfficeId === null) {
            $report['blocked'] = true;
            $report['blockers'][] = 'apply exige --primary-office=<id>';

            return $report;
        }

        try {
            DB::transaction(function () use ($primaryOfficeId, &$report): void {
                $this->ensurePrimaryOffice($primaryOfficeId);

                foreach (Office::query()->orderBy('id')->cursor() as $office) {
                    $this->backfillOffice($office);
                }

                $this->backfillPlatformMemberships();

                $parity = $this->assertGlobalParity();
                if ($parity !== []) {
                    throw new RuntimeException(
                        'Divergência de capacidades: '.implode('; ', array_slice($parity, 0, 10))
                    );
                }

                $report['sessions_revoked'] = $this->revokeSessionsSanitized();
                $report['applied'] = true;
            });
        } catch (Throwable $e) {
            $report['blocked'] = true;
            $report['blockers'][] = 'apply abortado: '.$e->getMessage();
            $report['applied'] = false;

            Log::warning('multitenant_rbac.migrate_aborted', [
                'reason' => $e->getMessage(),
            ]);
        }

        $final = $this->inventory($primaryOfficeId);
        $report['counts'] = $final['counts'];
        $report['offices'] = $final['offices'];
        $report['primary_office_id'] = $primaryOfficeId;
        if ($report['applied']) {
            $report['blocked'] = false;
            $report['blockers'] = [];
        }

        return $report;
    }

    /**
     * @return array{
     *     blocked: bool,
     *     blockers: list<string>,
     *     counts: array<string, int|array<string, int>>,
     *     primary_office_id: int|null,
     *     offices: list<array<string, mixed>>
     * }
     */
    public function inventory(?int $requestedPrimary = null): array
    {
        $blockers = [];
        $roleCounts = [
            'ADMIN' => 0,
            'OPERATOR' => 0,
            'VIEWER' => 0,
            'unknown_office_role' => 0,
            'PLATFORM_ADMIN' => 0,
            'tenant_admin' => 0,
            'tenant_user' => 0,
            'platform_role_filled' => 0,
        ];

        $officeRows = [];
        $orphanMemberships = 0;
        $officesWithoutAdmin = 0;

        foreach (Office::query()->orderBy('id')->get() as $office) {
            $memberships = OfficeMembership::query()
                ->where('office_id', $office->id)
                ->get();

            $adminCount = 0;
            $unknown = 0;
            foreach ($memberships as $m) {
                $roleValue = $m->getRawOriginal('role');
                if ($roleValue === OfficeRole::Admin->value
                    || $m->tenant_role === TenantRole::TenantAdmin) {
                    if ($m->is_active) {
                        $adminCount++;
                    }
                }
                match ($roleValue) {
                    'ADMIN' => $roleCounts['ADMIN']++,
                    'OPERATOR' => $roleCounts['OPERATOR']++,
                    'VIEWER' => $roleCounts['VIEWER']++,
                    default => $unknown++,
                };
                if ($m->tenant_role === TenantRole::TenantAdmin) {
                    $roleCounts['tenant_admin']++;
                }
                if ($m->tenant_role === TenantRole::TenantUser) {
                    $roleCounts['tenant_user']++;
                }
                if ($m->user_id && ! User::query()->whereKey($m->user_id)->exists()) {
                    $orphanMemberships++;
                }
            }
            $roleCounts['unknown_office_role'] += $unknown;
            if ($unknown > 0) {
                $blockers[] = "office_id={$office->id} possui {$unknown} papel(is) desconhecido(s)";
            }
            if ($adminCount === 0 && $memberships->where('is_active', true)->isNotEmpty()) {
                $officesWithoutAdmin++;
                $blockers[] = "office_id={$office->id} sem administrador ativo";
            }

            $officeRows[] = [
                'office_id' => $office->id,
                'memberships' => $memberships->count(),
                'admins' => $adminCount,
                'lifecycle' => $office->lifecycle_status?->value ?? null,
            ];
        }

        $platformAdmins = PlatformMembership::query()
            ->where('is_active', true)
            ->whereIn('role', PlatformRole::PlatformAdmin->storageValues())
            ->count();
        $roleCounts['PLATFORM_ADMIN'] = $platformAdmins;
        $roleCounts['platform_role_filled'] = PlatformMembership::query()
            ->whereNotNull('platform_role')
            ->count();

        if ($platformAdmins < 1) {
            $blockers[] = 'nenhum platform_admin ativo — execute recuperação break-glass antes';
        }

        $officeCount = Office::query()->count();
        $existingPrimary = PlatformSetting::query()
            ->whereKey(PlatformSetting::SINGLETON_ID)
            ->value('primary_office_id');
        $existingPrimary = $existingPrimary !== null ? (int) $existingPrimary : null;

        if ($officeCount === 0) {
            $blockers[] = 'zero tenants: use bootstrap/reconciliação com nome/slug explícitos';
        } elseif ($requestedPrimary === null && $existingPrimary === null) {
            $blockers[] = $officeCount === 1
                ? 'confirme o principal com --primary-office=<id> (há exatamente 1 tenant)'
                : 'múltiplos tenants sem primary_office_id — informe --primary-office=<id>';
        } elseif ($requestedPrimary !== null && ! Office::query()->whereKey($requestedPrimary)->exists()) {
            $blockers[] = "primary-office={$requestedPrimary} inexistente";
        }

        $pendingJobs = Schema::hasTable('jobs')
            ? (int) DB::table('jobs')->count()
            : 0;

        return [
            'blocked' => $blockers !== [],
            'blockers' => $blockers,
            'counts' => [
                'offices' => $officeCount,
                'roles' => $roleCounts,
                'orphan_memberships' => $orphanMemberships,
                'offices_without_admin' => $officesWithoutAdmin,
                'platform_admins_active' => $platformAdmins,
                'pending_jobs' => $pendingJobs,
            ],
            'primary_office_id' => $requestedPrimary ?? $existingPrimary,
            'offices' => $officeRows,
        ];
    }

    private function ensurePrimaryOffice(int $officeId): void
    {
        if (! Office::query()->whereKey($officeId)->exists()) {
            throw new RuntimeException("primary-office={$officeId} inexistente");
        }

        $settings = PlatformSetting::query()->find(PlatformSetting::SINGLETON_ID);
        if ($settings === null) {
            PlatformSetting::query()->create([
                'id' => PlatformSetting::SINGLETON_ID,
                'organization_name' => (string) config('app.name', 'Plataforma'),
                'primary_office_id' => $officeId,
                'onboarding_completed_at' => now(),
            ]);

            return;
        }

        $settings->primary_office_id = $officeId;
        $settings->save();
    }

    private function backfillOffice(Office $office): void
    {
        $operator = $this->upsertSystemProfile(
            $office,
            TenantPermissionProfile::SYSTEM_LEGACY_OPERATOR,
            'Operador (sistema)',
            TenantPermission::legacyOperatorSet()
        );
        $viewer = $this->upsertSystemProfile(
            $office,
            TenantPermissionProfile::SYSTEM_LEGACY_VIEWER,
            'Visualizador (sistema)',
            TenantPermission::legacyViewerSet()
        );

        $memberships = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($memberships as $membership) {
            $before = $this->legacyCapabilitySnapshot($membership);
            $roleValue = $membership->getRawOriginal('role');

            if ($roleValue === OfficeRole::Admin->value) {
                $membership->tenant_role = TenantRole::TenantAdmin;
                $membership->permission_profile_id = null;
            } elseif ($roleValue === OfficeRole::Operator->value) {
                $membership->tenant_role = TenantRole::TenantUser;
                $membership->permission_profile_id = $operator->id;
            } elseif ($roleValue === OfficeRole::Viewer->value) {
                $membership->tenant_role = TenantRole::TenantUser;
                $membership->permission_profile_id = $viewer->id;
            } else {
                throw new RuntimeException(
                    "membership_id={$membership->id} papel desconhecido: ".($roleValue ?? 'null')
                );
            }

            $membership->authorization_version = max(1, (int) $membership->authorization_version);
            $membership->save();

            $fresh = $membership->fresh(['permissionProfile.permissionRows']);
            $fresh->assertCanonicalInvariants();

            $after = $this->canonicalCapabilitySnapshot($fresh);
            if ($before !== $after) {
                throw new RuntimeException(
                    "membership_id={$membership->id} divergência de capacidades"
                );
            }
        }
    }

    /**
     * @param  list<TenantPermission>  $permissions
     */
    private function upsertSystemProfile(
        Office $office,
        string $key,
        string $name,
        array $permissions,
    ): TenantPermissionProfile {
        $profile = TenantPermissionProfile::query()->firstOrNew([
            'office_id' => $office->id,
            'key' => $key,
        ]);
        $profile->name = $name;
        $profile->is_system = true;
        $profile->is_active = true;
        if (! $profile->exists) {
            $profile->authorization_version = 1;
        }
        $profile->save();

        $desired = array_map(static fn (TenantPermission $p) => $p->value, $permissions);
        sort($desired, SORT_STRING);
        if ($profile->permissionKeys() !== $desired) {
            $profile->syncPermissionKeys($permissions, allowSystem: true);
        }

        return $profile->fresh(['permissionRows']);
    }

    private function backfillPlatformMemberships(): void
    {
        $rows = PlatformMembership::query()->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $role = PlatformRole::tryFromStorage($row->getRawOriginal('role'));
            if ($role === null) {
                throw new RuntimeException(
                    "platform_membership_id={$row->id} papel desconhecido"
                );
            }
            $row->platform_role = $role;
            $row->save();
        }
    }

    /**
     * Snapshot de capacidades equivalentes ao papel legado (oráculo de paridade).
     *
     * @return list<string>
     */
    public function legacyCapabilitySnapshot(OfficeMembership $membership): array
    {
        $roleValue = $membership->getRawOriginal('role')
            ?? ($membership->role instanceof OfficeRole ? $membership->role->value : null);

        $role = is_string($roleValue) ? OfficeRole::tryFrom($roleValue) : null;
        if ($role === null) {
            return [];
        }

        return match ($role) {
            OfficeRole::Admin => TenantPermission::orderedValues(),
            OfficeRole::Operator => $this->permissionValues(TenantPermission::legacyOperatorSet()),
            OfficeRole::Viewer => $this->permissionValues(TenantPermission::legacyViewerSet()),
        };
    }

    /**
     * @return list<string>
     */
    public function canonicalCapabilitySnapshot(OfficeMembership $membership): array
    {
        $role = $membership->tenant_role;
        if ($role === TenantRole::TenantAdmin) {
            return TenantPermission::orderedValues();
        }

        if ($role === TenantRole::TenantUser) {
            $profile = $membership->permissionProfile;
            if ($profile === null || ! $profile->is_active) {
                return [];
            }

            return $profile->permissionKeys();
        }

        return [];
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return list<string>
     */
    private function permissionValues(array $permissions): array
    {
        $keys = array_map(static fn (TenantPermission $p) => $p->value, $permissions);
        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function assertGlobalParity(): array
    {
        $errors = [];
        foreach (
            OfficeMembership::query()
                ->with('permissionProfile.permissionRows')
                ->orderBy('id')
                ->cursor() as $m
        ) {
            if ($this->legacyCapabilitySnapshot($m) !== $this->canonicalCapabilitySnapshot($m)) {
                $errors[] = "membership_id={$m->id}";
            }
        }

        return $errors;
    }

    private function revokeSessionsSanitized(): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        $count = (int) DB::table('sessions')->count();
        DB::table('sessions')->delete();

        Log::info('multitenant_rbac.sessions_revoked', [
            'count' => $count,
        ]);

        return $count;
    }
}
