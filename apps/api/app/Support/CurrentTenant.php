<?php

namespace App\Support;

use App\Enums\PlatformRole;
use App\Enums\TenantAccessMode;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Contexto do tenant ativo.
 *
 * Ordem de resolução (PLATFORM_ADMIN com flag privilegiada ON):
 * 1. Seleção global válida da sessão/cache
 * 2. platform_memberships.default_tenant_id se Tenant ativo
 * 3. Sem contexto → null (caller usa context_status / 409)
 *
 * Ordem de resolução (membership):
 * 1. Sessão SPA (`current_tenant_id`) se membership ainda válida
 * 2. `users.selected_tenant_id` (troca explícita persistida)
 * 3. Primeira membership ativa (determinística por id)
 *
 * Nunca confia tenant_id fornecido livremente pelo cliente HTTP.
 * Em modo privilegiado, `role()` é o papel efetivo (ADMIN); `realMembership()`
 * preserva o vínculo real quando a conta dual possui membership no Tenant.
 */
class CurrentTenant
{
    public const SESSION_KEY = 'current_tenant_id';

    /** @see PlatformPrivilegedContext::SESSION_KEY */
    public const PLATFORM_SESSION_KEY = PlatformPrivilegedContext::SESSION_KEY;

    public const CONTEXT_STATUS_OK = 'ok';

    public const CONTEXT_STATUS_REQUIRED = 'tenant_context_required';

    private ?Tenant $tenant = null;

    private ?TenantMembership $realMembership = null;

    private ?TenantRole $role = null;

    private ?TenantRole $realTenantRole = null;

    private ?TenantAccessMode $accessMode = null;

    private ?int $boundUserId = null;

    private ?User $actor = null;

    private ?string $contextStatus = null;

    public function resolve(?Authenticatable $user = null): ?Tenant
    {
        $user = $user ?? auth()->user();

        // Já ligado explicitamente (bind / bindPlatformPrivileged) — reutiliza se o ator bate.
        if ($this->tenant !== null && $this->boundUserId !== null) {
            if ($user === null || ($user instanceof User && $user->id === $this->boundUserId)) {
                return $this->tenant;
            }
        }

        if (! $user instanceof User || ! $user->is_active) {
            $this->clear();

            return null;
        }

        // 1. Contexto privilegiado da plataforma (sem membership fictícia)
        if ($this->tryBindPlatformPrivileged($user)) {
            return $this->tenant;
        }

        $membership = $this->resolveMembership($user);

        if ($membership === null) {
            $this->clear();
            if ($user->isPlatformAdmin()) {
                $this->contextStatus = self::CONTEXT_STATUS_REQUIRED;
            }

            return null;
        }

        $this->bind($user, $membership);

        return $this->tenant;
    }

    /**
     * Resolve membership ativa: sessão → preferência persistida → primeira ativa.
     * Não considera seleção privilegiada da plataforma.
     */
    public function resolveMembership(User $user): ?TenantMembership
    {
        $candidates = [];

        $sessionTenantId = $this->sessionTenantId();
        if ($sessionTenantId !== null) {
            $candidates[] = $sessionTenantId;
        }

        if ($user->selected_tenant_id !== null) {
            $candidates[] = (int) $user->selected_tenant_id;
        }

        foreach (array_unique($candidates) as $tenantId) {
            $membership = $this->activeMembershipFor($user, $tenantId);
            if ($membership !== null) {
                return $membership;
            }

            // Preferência inválida (revogada / tenant inativo)
            if ($sessionTenantId === $tenantId) {
                $this->forgetSessionTenantId();
            }
            if ((int) $user->selected_tenant_id === $tenantId) {
                $user->forceFill(['selected_tenant_id' => null])->saveQuietly();
            }
        }

        return $user->memberships()
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q
                ->where('is_active', true)
                ->where('lifecycle_status', TenantLifecycleStatus::Active->value))
            ->with('tenant')
            ->orderBy('id')
            ->first();
    }

    public function bind(User $user, TenantMembership $membership): void
    {
        $this->boundUserId = $user->id;
        $this->realMembership = $membership;
        $this->tenant = $membership->tenant;
        $this->role = $membership->role;
        $this->realTenantRole = $membership->role;
        $this->accessMode = TenantAccessMode::Membership;
        $this->actor = $user;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    /**
     * Liga contexto privilegiado (ator real, papel efetivo ADMIN).
     * Preserva membership real quando a conta dual possui vínculo no Tenant.
     */
    public function bindPlatformPrivileged(User $user, Tenant $tenant): void
    {
        $real = $this->activeMembershipFor($user, (int) $tenant->id);

        $this->boundUserId = $user->id;
        $this->tenant = $tenant;
        $this->role = TenantRole::TenantAdmin;
        $this->accessMode = TenantAccessMode::PlatformPrivileged;
        $this->actor = $user;
        $this->realMembership = $real;
        $this->realTenantRole = $real?->role;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    /**
     * Liga o Tenant para rotinas de sistema (scheduler/console) sem ator HTTP.
     * Authorship (requested_by_membership_id) permanece null.
     */
    public function bindSystem(Tenant $tenant): void
    {
        $this->boundUserId = 0;
        $this->tenant = $tenant;
        $this->realMembership = null;
        $this->role = TenantRole::TenantAdmin;
        $this->realTenantRole = null;
        $this->accessMode = TenantAccessMode::PlatformPrivileged;
        $this->actor = null;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    public function id(): ?int
    {
        return $this->resolve()?->id;
    }

    public function tenant(): Tenant
    {
        $tenant = $this->resolve();

        if ($tenant === null) {
            throw new RuntimeException('Nenhum escritório ativo para o usuário autenticado.');
        }

        return $tenant;
    }

    /**
     * Papel efetivo (ADMIN em modo privilegiado; papel da membership no modo membership).
     */
    public function role(): ?TenantRole
    {
        $this->resolve();

        return $this->role;
    }

    /**
     * Membership real do ator no Tenant corrente (null se admin global sem vínculo).
     */
    public function realMembership(): ?TenantMembership
    {
        $this->resolve();

        return $this->realMembership;
    }

    /**
     * Papel real da membership (null se sem membership real).
     */
    public function realTenantRole(): ?TenantRole
    {
        $this->resolve();

        return $this->realTenantRole;
    }

    public function accessMode(): ?TenantAccessMode
    {
        $this->resolve();

        return $this->accessMode;
    }

    /**
     * Status do contexto após resolve: ok | tenant_context_required | null (sem ator).
     */
    public function contextStatus(): ?string
    {
        $this->resolve();

        return $this->contextStatus;
    }

    /**
     * Ator real do contexto (usuário autenticado). Em modo privilegiado é o PLATFORM_ADMIN.
     */
    public function actor(): ?User
    {
        $this->resolve();

        return $this->actor;
    }

    public function isPlatformPrivileged(): bool
    {
        $this->resolve();

        return $this->accessMode === TenantAccessMode::PlatformPrivileged;
    }

    public function hasRealMembership(): bool
    {
        $this->resolve();

        return $this->realMembership !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->realMembership = null;
        $this->role = null;
        $this->realTenantRole = null;
        $this->accessMode = null;
        $this->boundUserId = null;
        $this->actor = null;
        $this->contextStatus = null;
    }

    /**
     * Never trust client-supplied tenant_id. Always use resolved tenant.
     */
    public function assertBelongsToTenant(int $tenantId): void
    {
        if ($this->id() !== $tenantId) {
            abort(404);
        }
    }

    /**
     * Membership de plataforma ativa do ator (para default_tenant_id).
     */
    public function platformMembership(User $user): ?PlatformMembership
    {
        return $user->platformMemberships()
            ->where('is_active', true)
            ->where('role', PlatformRole::PlatformAdmin->value)
            ->first();
    }

    public function defaultTenantId(User $user): ?int
    {
        $pm = $this->platformMembership($user);
        if ($pm?->default_tenant_id === null) {
            return null;
        }

        return (int) $pm->default_tenant_id;
    }

    /**
     * Persiste default_tenant_id na membership de plataforma (sem criar TenantMembership).
     */
    public function persistDefaultTenant(User $user, int $tenantId): void
    {
        $pm = $this->platformMembership($user);
        if ($pm === null) {
            return;
        }

        $pm->forceFill(['default_tenant_id' => $tenantId])->save();
    }

    private function tryBindPlatformPrivileged(User $user): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        if (! FeatureFlags::isPlatformPrivilegedContextEnabled()) {
            return false;
        }

        $platformTenantId = $this->resolvePlatformTenantId($user);
        if ($platformTenantId === null) {
            $this->contextStatus = self::CONTEXT_STATUS_REQUIRED;

            return false;
        }

        $tenant = Tenant::query()
            ->whereKey($platformTenantId)
            ->where('is_active', true)
            ->where('lifecycle_status', TenantLifecycleStatus::Active->value)
            ->first();

        if ($tenant === null) {
            // Seleção de sessão inválida: limpa só a sessão; default inativo permanece
            // e força tenant_context_required (sem fallback silencioso).
            $sessionId = $this->platformSelectedTenantId($user);
            if ($sessionId !== null && $sessionId === $platformTenantId) {
                $this->forgetPlatformSelection($user);
            }
            $this->contextStatus = self::CONTEXT_STATUS_REQUIRED;

            return false;
        }

        $this->bindPlatformPrivileged($user, $tenant);

        return true;
    }

    /**
     * Sessão/cache privilegiado → default_tenant_id ativo.
     */
    private function resolvePlatformTenantId(User $user): ?int
    {
        $fromSession = $this->platformSelectedTenantId($user);
        if ($fromSession !== null) {
            return $fromSession;
        }

        $defaultId = $this->defaultTenantId($user);
        if ($defaultId === null) {
            return null;
        }

        // Propaga default válido para a sessão da requisição corrente.
        $active = Tenant::query()
            ->whereKey($defaultId)
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            return $defaultId; // caller trata inativo como context_required
        }

        $this->rememberPlatformSelection($user, $defaultId);

        return $defaultId;
    }

    /**
     * Tenant id privilegiado: sessão SPA → cache por usuário (token/testes).
     * Nunca usa users.selected_tenant_id.
     */
    public function platformSelectedTenantId(?User $user = null): ?int
    {
        $fromSession = $this->sessionInt(self::PLATFORM_SESSION_KEY);
        if ($fromSession !== null) {
            return $fromSession;
        }

        $user ??= auth()->user() instanceof User ? auth()->user() : null;
        if ($user instanceof User) {
            $cached = Cache::get($this->platformCacheKey($user));
            if (is_numeric($cached)) {
                return (int) $cached;
            }
        }

        return null;
    }

    /**
     * Persistência da seleção privilegiada (sessão + cache; sem membership).
     */
    public function rememberPlatformSelection(User $user, int $tenantId): void
    {
        if (app()->bound('request')) {
            $request = request();
            if ($request !== null && $request->hasSession()) {
                $request->session()->put(self::PLATFORM_SESSION_KEY, $tenantId);
            }
        }

        Cache::put(
            $this->platformCacheKey($user),
            $tenantId,
            now()->addDays(7),
        );
    }

    public function forgetPlatformSelection(?User $user = null): void
    {
        $this->forgetSessionKey(self::PLATFORM_SESSION_KEY);

        $user ??= auth()->user() instanceof User ? auth()->user() : null;
        if ($user instanceof User) {
            Cache::forget($this->platformCacheKey($user));
        }
    }

    public function platformCacheKey(User $user): string
    {
        return 'platform.selected_tenant.'.$user->id;
    }

    private function activeMembershipFor(User $user, int $tenantId): ?TenantMembership
    {
        return $user->memberships()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q
                ->where('is_active', true)
                ->where('lifecycle_status', TenantLifecycleStatus::Active->value))
            ->with('tenant')
            ->first();
    }

    private function sessionTenantId(): ?int
    {
        return $this->sessionInt(self::SESSION_KEY);
    }

    private function sessionInt(string $key): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        if ($request === null || ! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function forgetSessionTenantId(): void
    {
        $this->forgetSessionKey(self::SESSION_KEY);
    }

    private function forgetSessionKey(string $key): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $request = request();
        if ($request === null || ! $request->hasSession()) {
            return;
        }

        $request->session()->forget($key);
    }
}
