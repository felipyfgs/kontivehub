<?php

namespace App\Services\Platform;

use App\Enums\TenantAccessMode;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use App\Support\PlatformPrivilegedContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Seletor global de tenant para PLATFORM_ADMIN (modo privilegiado).
 *
 * Não cria TenantMembership, não altera users.selected_tenant_id.
 * Toda seleção válida atualiza sessão + default_tenant_id atomicamente.
 */
final class PlatformTenantSelectService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSwitchService $tenantSwitch,
    ) {}

    /**
     * Lista todos os tenants (ativos, inativos e pendentes) com metadados sanitizados.
     *
     * @return list<array{id: int, name: string|null, slug: string|null, is_active: bool, status: string, lifecycle_status: string, selectable: bool}>
     */
    public function listTenants(): array
    {
        return Tenant::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active', 'lifecycle_status'])
            ->map(fn (Tenant $o) => $this->summarizeTenant($o))
            ->values()
            ->all();
    }

    /**
     * Envelope canônico de GET /platform/tenants.
     *
     * @return array{tenants: list<array>, selected_tenant_id: int|null, default_tenant_id: int|null}
     */
    public function listEnvelope(User $user): array
    {
        $this->currentTenant->clear();
        $resolved = null;
        if (FeatureFlags::isPlatformPrivilegedContextEnabled() && $user->isPlatformAdmin()) {
            $resolved = $this->currentTenant->resolve($user);
        }

        return [
            'tenants' => $this->listTenants(),
            'selected_tenant_id' => $resolved?->id,
            'default_tenant_id' => $this->currentTenant->defaultTenantId($user),
        ];
    }

    /**
     * @throws HttpException
     */
    public function select(User $user, int $targetTenantId, Request $request): Tenant
    {
        if (! $user->is_active || ! $user->isPlatformAdmin()) {
            abort(403, 'Ação restrita a administradores da plataforma.');
        }

        if (! FeatureFlags::isPlatformPrivilegedContextEnabled()) {
            if ($this->hasActiveRealMembership($user, $targetTenantId)) {
                return $this->tenantSwitch->switchTo($user, $targetTenantId, $request);
            }

            $this->auditDenied($user, $targetTenantId, 'privileged_context_disabled');

            throw new HttpException(
                403,
                'Contexto privilegiado da plataforma indisponível.',
                null,
                ['X-Error-Code' => 'privileged_context_disabled'],
            );
        }

        $fromTenantId = $this->currentTenant->isPlatformPrivileged()
            ? $this->currentTenant->id()
            : null;

        $tenant = Tenant::query()
            ->whereKey($targetTenantId)
            ->where('is_active', true)
            ->first();

        if ($tenant === null || ! $tenant->lifecycle_status?->isSelectable()) {
            $this->auditDenied($user, $targetTenantId, 'tenant_not_found_or_inactive');

            abort(404, 'Escritório não encontrado.');
        }

        DB::transaction(function () use ($user, $tenant, $request): void {
            // Sessão SPA + cache por usuário (token clients / suite de testes).
            $this->currentTenant->rememberPlatformSelection($user, $tenant->id);
            // Persistência do padrão global (sobrevive ao próximo login).
            $this->currentTenant->persistDefaultTenant($user, $tenant->id);

            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->put(PlatformPrivilegedContext::SESSION_KEY, $tenant->id);
            }
        });

        $this->currentTenant->clear();
        $this->currentTenant->bindPlatformPrivileged($user, $tenant);

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $user->id,
            tenantId: $tenant->id,
            action: PlatformPrivilegedAuditEvent::ACTION_SELECT_TENANT,
            result: PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
            targetType: Tenant::class,
            targetId: $tenant->id,
            requestId: $this->requestId($request),
            metadata: [
                'access_mode' => TenantAccessMode::PlatformPrivileged->value,
                'from_tenant_id' => $fromTenantId,
                'to_tenant_id' => $tenant->id,
                'membership_created' => false,
                'default_tenant_id' => $tenant->id,
                'selected_tenant_id_unchanged' => $user->selected_tenant_id,
            ],
        );

        return $tenant;
    }

    private function hasActiveRealMembership(User $user, int $targetTenantId): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        return TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $targetTenantId)
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    /**
     * Remove a seleção privilegiada da sessão (não apaga default_tenant_id).
     */
    public function clear(User $user, Request $request): void
    {
        if (! $user->is_active || ! $user->isPlatformAdmin()) {
            abort(403, 'Ação restrita a administradores da plataforma.');
        }

        $previousTenantId = $this->currentTenant->platformSelectedTenantId($user);
        $this->currentTenant->forgetPlatformSelection($user);
        $this->currentTenant->clear();

        if ($previousTenantId !== null) {
            PlatformPrivilegedAuditEvent::record(
                actorUserId: $user->id,
                tenantId: $previousTenantId,
                action: PlatformPrivilegedAuditEvent::ACTION_CLEAR_TENANT,
                result: PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
                targetType: Tenant::class,
                targetId: $previousTenantId,
                requestId: $this->requestId($request),
                metadata: [
                    'access_mode' => TenantAccessMode::PlatformPrivileged->value,
                    'cleared_tenant_id' => $previousTenantId,
                ],
            );
        }
    }

    /**
     * Snapshot do contexto privilegiado atual (sem conteúdo fiscal).
     *
     * @return array{
     *     enabled: bool,
     *     selected: bool,
     *     access_mode: string|null,
     *     tenant: array{id: int, name: string|null, slug: string|null}|null,
     *     tenant_role: string|null,
     *     real_tenant_role: string|null,
     *     has_real_membership: bool,
     *     actor_user_id: int|null,
     *     default_tenant_id: int|null
     * }
     */
    public function current(User $user): array
    {
        $enabled = FeatureFlags::isPlatformPrivilegedContextEnabled();
        $this->currentTenant->clear();
        $tenant = $enabled && $user->isPlatformAdmin()
            ? $this->currentTenant->resolve($user)
            : null;

        $privileged = $tenant !== null && $this->currentTenant->isPlatformPrivileged();

        return [
            'enabled' => $enabled,
            'selected' => $privileged,
            'access_mode' => $privileged
                ? TenantAccessMode::PlatformPrivileged->value
                : $this->currentTenant->accessMode()?->value,
            'tenant' => $privileged && $tenant !== null ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'tenant_role' => $privileged ? TenantRole::TenantAdmin->value : null,
            'real_tenant_role' => $privileged ? $this->currentTenant->realTenantRole()?->value : null,
            'has_real_membership' => $privileged && $this->currentTenant->hasRealMembership(),
            'actor_user_id' => $privileged ? $user->id : null,
            'default_tenant_id' => $this->currentTenant->defaultTenantId($user),
        ];
    }

    /**
     * @return array{id: int, name: string|null, slug: string|null, is_active: bool, status: string, lifecycle_status: string, selectable: bool}
     */
    private function summarizeTenant(Tenant $o): array
    {
        $active = (bool) $o->is_active;
        $lifecycle = $o->lifecycle_status instanceof TenantLifecycleStatus
            ? $o->lifecycle_status->value
            : (string) ($o->getAttribute('lifecycle_status') ?? 'ACTIVE');

        $lifecycleStatus = $o->lifecycle_status instanceof TenantLifecycleStatus
            ? $o->lifecycle_status
            : TenantLifecycleStatus::tryFrom($o->getAttribute('lifecycle_status') ?? 'ACTIVE');

        $status = match (true) {
            $lifecycle === 'PENDING_ACTIVATION' => 'pending_activation',
            $active => 'active',
            default => 'inactive',
        };

        return [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'is_active' => $active,
            'status' => $status,
            'lifecycle_status' => $lifecycle,
            'selectable' => $active && $lifecycleStatus instanceof TenantLifecycleStatus && $lifecycleStatus->isSelectable(),
        ];
    }

    private function auditDenied(User $user, int $targetTenantId, string $reason): void
    {
        $tenantExists = Tenant::query()->whereKey($targetTenantId)->exists();
        if (! $tenantExists) {
            return;
        }

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $user->id,
            tenantId: $targetTenantId,
            action: PlatformPrivilegedAuditEvent::ACTION_SELECT_TENANT,
            result: PlatformPrivilegedAuditEvent::RESULT_DENIED,
            targetType: Tenant::class,
            targetId: $targetTenantId,
            requestId: $this->requestId(request()),
            metadata: [
                'reason' => $reason,
                'access_mode' => TenantAccessMode::PlatformPrivileged->value,
            ],
        );
    }

    private function requestId(?Request $request): string
    {
        $existing = $request?->attributes->get('correlation_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        $request?->attributes->set('correlation_id', $id);

        return $id;
    }
}
