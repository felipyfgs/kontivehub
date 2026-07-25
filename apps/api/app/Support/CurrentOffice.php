<?php

namespace App\Support;

use App\Enums\OfficeAccessMode;
use App\Enums\OfficeLifecycleStatus;
use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\PlatformMembership;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Contexto do tenant ativo.
 *
 * Ordem de resolução (PLATFORM_ADMIN com flag privilegiada ON):
 * 1. Seleção global válida da sessão/cache
 * 2. platform_memberships.default_office_id se Office ativo
 * 3. Sem contexto → null (caller usa context_status / 409)
 *
 * Ordem de resolução (membership):
 * 1. Sessão SPA (`current_office_id`) se membership ainda válida
 * 2. `users.selected_office_id` (troca explícita persistida)
 * 3. Primeira membership ativa (determinística por id)
 *
 * Nunca confia office_id fornecido livremente pelo cliente HTTP.
 * Em modo privilegiado, `role()` é o papel efetivo (ADMIN); `realMembership()`
 * preserva o vínculo real quando a conta dual possui membership no Office.
 */
class CurrentOffice
{
    public const SESSION_KEY = 'current_office_id';

    /** @see PlatformPrivilegedContext::SESSION_KEY */
    public const PLATFORM_SESSION_KEY = PlatformPrivilegedContext::SESSION_KEY;

    public const CONTEXT_STATUS_OK = 'ok';

    public const CONTEXT_STATUS_REQUIRED = 'office_context_required';

    private ?Office $office = null;

    private ?OfficeMembership $membership = null;

    private ?OfficeMembership $realMembership = null;

    private ?OfficeRole $role = null;

    private ?OfficeRole $realOfficeRole = null;

    private ?OfficeAccessMode $accessMode = null;

    private ?int $boundUserId = null;

    private ?User $actor = null;

    private ?string $contextStatus = null;

    public function resolve(?Authenticatable $user = null): ?Office
    {
        $user = $user ?? auth()->user();

        // Já ligado explicitamente (bind / bindPlatformPrivileged) — reutiliza se o ator bate.
        if ($this->office !== null && $this->boundUserId !== null) {
            if ($user === null || ($user instanceof User && $user->id === $this->boundUserId)) {
                return $this->office;
            }
        }

        if (! $user instanceof User || ! $user->is_active) {
            $this->clear();

            return null;
        }

        // 1. Contexto privilegiado da plataforma (sem membership fictícia)
        if ($this->tryBindPlatformPrivileged($user)) {
            return $this->office;
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

        return $this->office;
    }

    /**
     * Resolve membership ativa: sessão → preferência persistida → primeira ativa.
     * Não considera seleção privilegiada da plataforma.
     */
    public function resolveMembership(User $user): ?OfficeMembership
    {
        $candidates = [];

        $sessionOfficeId = $this->sessionOfficeId();
        if ($sessionOfficeId !== null) {
            $candidates[] = $sessionOfficeId;
        }

        if ($user->selected_office_id !== null) {
            $candidates[] = (int) $user->selected_office_id;
        }

        foreach (array_unique($candidates) as $officeId) {
            $membership = $this->activeMembershipFor($user, $officeId);
            if ($membership !== null) {
                return $membership;
            }

            // Preferência inválida (revogada / office inativo)
            if ($sessionOfficeId === $officeId) {
                $this->forgetSessionOfficeId();
            }
            if ((int) $user->selected_office_id === $officeId) {
                $user->forceFill(['selected_office_id' => null])->saveQuietly();
            }
        }

        return $user->memberships()
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q
                ->where('is_active', true)
                ->where('lifecycle_status', OfficeLifecycleStatus::Active->value))
            ->with('office')
            ->orderBy('id')
            ->first();
    }

    public function bind(User $user, OfficeMembership $membership): void
    {
        $this->boundUserId = $user->id;
        $this->membership = $membership;
        $this->realMembership = $membership;
        $this->office = $membership->office;
        $this->role = $membership->role;
        $this->realOfficeRole = $membership->role;
        $this->accessMode = OfficeAccessMode::Membership;
        $this->actor = $user;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    /**
     * Liga contexto privilegiado (ator real, papel efetivo ADMIN).
     * Preserva membership real quando a conta dual possui vínculo no Office.
     */
    public function bindPlatformPrivileged(User $user, Office $office): void
    {
        $real = $this->activeMembershipFor($user, (int) $office->id);

        $this->boundUserId = $user->id;
        $this->office = $office;
        $this->role = OfficeRole::Admin;
        $this->accessMode = OfficeAccessMode::PlatformPrivileged;
        $this->actor = $user;
        $this->realMembership = $real;
        $this->realOfficeRole = $real?->role;
        // membership() permanece a membership "operacional" real quando existe (dual),
        // null para admin global puro — Work mutações usam realMembership().
        $this->membership = $real;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    /**
     * Liga o Office para rotinas de sistema (scheduler/console) sem ator HTTP.
     * Authorship (requested_by_membership_id) permanece null.
     */
    public function bindSystem(Office $office): void
    {
        $this->boundUserId = 0;
        $this->office = $office;
        $this->membership = null;
        $this->realMembership = null;
        $this->role = OfficeRole::Admin;
        $this->realOfficeRole = null;
        $this->accessMode = OfficeAccessMode::PlatformPrivileged;
        $this->actor = null;
        $this->contextStatus = self::CONTEXT_STATUS_OK;
    }

    public function id(): ?int
    {
        return $this->resolve()?->id;
    }

    public function office(): Office
    {
        $office = $this->resolve();

        if ($office === null) {
            throw new RuntimeException('Nenhum escritório ativo para o usuário autenticado.');
        }

        return $office;
    }

    /**
     * Papel efetivo (ADMIN em modo privilegiado; papel da membership no modo membership).
     */
    public function role(): ?OfficeRole
    {
        $this->resolve();

        return $this->role;
    }

    /**
     * Membership operacional (real quando dual em privilegiado; membership no modo comum).
     *
     * @deprecated Prefer realMembership() for Work authorship gates.
     */
    public function membership(): ?OfficeMembership
    {
        $this->resolve();

        return $this->membership;
    }

    /**
     * Membership real do ator no Office corrente (null se admin global sem vínculo).
     */
    public function realMembership(): ?OfficeMembership
    {
        $this->resolve();

        return $this->realMembership;
    }

    /**
     * Papel real da membership (null se sem membership real).
     */
    public function realOfficeRole(): ?OfficeRole
    {
        $this->resolve();

        return $this->realOfficeRole;
    }

    public function accessMode(): ?OfficeAccessMode
    {
        $this->resolve();

        return $this->accessMode;
    }

    /**
     * Status do contexto após resolve: ok | office_context_required | null (sem ator).
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

        return $this->accessMode === OfficeAccessMode::PlatformPrivileged;
    }

    public function hasRealMembership(): bool
    {
        $this->resolve();

        return $this->realMembership !== null;
    }

    public function clear(): void
    {
        $this->office = null;
        $this->membership = null;
        $this->realMembership = null;
        $this->role = null;
        $this->realOfficeRole = null;
        $this->accessMode = null;
        $this->boundUserId = null;
        $this->actor = null;
        $this->contextStatus = null;
    }

    /**
     * Never trust client-supplied office_id. Always use resolved office.
     */
    public function assertBelongsToOffice(int $officeId): void
    {
        if ($this->id() !== $officeId) {
            abort(404);
        }
    }

    /**
     * Membership de plataforma ativa do ator (para default_office_id).
     */
    public function platformMembership(User $user): ?PlatformMembership
    {
        return $user->platformMemberships()
            ->where('is_active', true)
            ->where('role', PlatformRole::PlatformAdmin->value)
            ->first();
    }

    public function defaultOfficeId(User $user): ?int
    {
        $pm = $this->platformMembership($user);
        if ($pm?->default_office_id === null) {
            return null;
        }

        return (int) $pm->default_office_id;
    }

    /**
     * Persiste default_office_id na membership de plataforma (sem criar OfficeMembership).
     */
    public function persistDefaultOffice(User $user, int $officeId): void
    {
        $pm = $this->platformMembership($user);
        if ($pm === null) {
            return;
        }

        $pm->forceFill(['default_office_id' => $officeId])->save();
    }

    private function tryBindPlatformPrivileged(User $user): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        if (! FeatureFlags::isPlatformPrivilegedContextEnabled()) {
            return false;
        }

        $platformOfficeId = $this->resolvePlatformOfficeId($user);
        if ($platformOfficeId === null) {
            $this->contextStatus = self::CONTEXT_STATUS_REQUIRED;

            return false;
        }

        $office = Office::query()
            ->whereKey($platformOfficeId)
            ->where('is_active', true)
            ->where('lifecycle_status', OfficeLifecycleStatus::Active->value)
            ->first();

        if ($office === null) {
            // Seleção de sessão inválida: limpa só a sessão; default inativo permanece
            // e força office_context_required (sem fallback silencioso).
            $sessionId = $this->platformSelectedOfficeId($user);
            if ($sessionId !== null && $sessionId === $platformOfficeId) {
                $this->forgetPlatformSelection($user);
            }
            $this->contextStatus = self::CONTEXT_STATUS_REQUIRED;

            return false;
        }

        $this->bindPlatformPrivileged($user, $office);

        return true;
    }

    /**
     * Sessão/cache privilegiado → default_office_id ativo.
     */
    private function resolvePlatformOfficeId(User $user): ?int
    {
        $fromSession = $this->platformSelectedOfficeId($user);
        if ($fromSession !== null) {
            return $fromSession;
        }

        $defaultId = $this->defaultOfficeId($user);
        if ($defaultId === null) {
            return null;
        }

        // Propaga default válido para a sessão da requisição corrente.
        $active = Office::query()
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
     * Office id privilegiado: sessão SPA → cache por usuário (token/testes).
     * Nunca usa users.selected_office_id.
     */
    public function platformSelectedOfficeId(?User $user = null): ?int
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
    public function rememberPlatformSelection(User $user, int $officeId): void
    {
        if (app()->bound('request')) {
            $request = request();
            if ($request !== null && $request->hasSession()) {
                $request->session()->put(self::PLATFORM_SESSION_KEY, $officeId);
            }
        }

        Cache::put(
            $this->platformCacheKey($user),
            $officeId,
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
        return 'platform.selected_office.'.$user->id;
    }

    private function activeMembershipFor(User $user, int $officeId): ?OfficeMembership
    {
        return $user->memberships()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q
                ->where('is_active', true)
                ->where('lifecycle_status', OfficeLifecycleStatus::Active->value))
            ->with('office')
            ->first();
    }

    private function sessionOfficeId(): ?int
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

    private function forgetSessionOfficeId(): void
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
