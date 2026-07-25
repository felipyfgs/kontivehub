<?php

namespace App\Services\Platform;

use App\Enums\OfficeAccessMode;
use App\Enums\OfficeLifecycleStatus;
use App\Enums\OfficeRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\User;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use App\Support\PlatformPrivilegedContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Seletor global de office para PLATFORM_ADMIN (modo privilegiado).
 *
 * Não cria OfficeMembership, não altera users.selected_office_id.
 * Toda seleção válida atualiza sessão + default_office_id atomicamente.
 */
final class PlatformOfficeSelectService
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantSwitchService $tenantSwitch,
    ) {}

    /**
     * Lista todos os offices (ativos, inativos e pendentes) com metadados sanitizados.
     *
     * @return list<array{id: int, name: string|null, slug: string|null, is_active: bool, status: string, lifecycle_status: string, selectable: bool}>
     */
    public function listOffices(): array
    {
        return Office::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'is_active', 'lifecycle_status'])
            ->map(fn (Office $o) => $this->summarizeOffice($o))
            ->values()
            ->all();
    }

    /**
     * Envelope canônico de GET /platform/offices.
     *
     * @return array{offices: list<array>, selected_office_id: int|null, default_office_id: int|null}
     */
    public function listEnvelope(User $user): array
    {
        $this->currentOffice->clear();
        $resolved = null;
        if (FeatureFlags::isPlatformPrivilegedContextEnabled() && $user->isPlatformAdmin()) {
            $resolved = $this->currentOffice->resolve($user);
        }

        return [
            'offices' => $this->listOffices(),
            'selected_office_id' => $resolved?->id,
            'default_office_id' => $this->currentOffice->defaultOfficeId($user),
        ];
    }

    /**
     * @throws HttpException
     */
    public function select(User $user, int $targetOfficeId, Request $request): Office
    {
        if (! $user->is_active || ! $user->isPlatformAdmin()) {
            abort(403, 'Ação restrita a administradores da plataforma.');
        }

        if (! FeatureFlags::isPlatformPrivilegedContextEnabled()) {
            if ($this->hasActiveRealMembership($user, $targetOfficeId)) {
                return $this->tenantSwitch->switchTo($user, $targetOfficeId, $request);
            }

            $this->auditDenied($user, $targetOfficeId, 'privileged_context_disabled');

            throw new HttpException(
                403,
                'Contexto privilegiado da plataforma indisponível.',
                null,
                ['X-Error-Code' => 'privileged_context_disabled'],
            );
        }

        $fromOfficeId = $this->currentOffice->isPlatformPrivileged()
            ? $this->currentOffice->id()
            : null;

        $office = Office::query()
            ->whereKey($targetOfficeId)
            ->where('is_active', true)
            ->first();

        if ($office === null || ! $office->lifecycle_status?->isSelectable()) {
            $this->auditDenied($user, $targetOfficeId, 'office_not_found_or_inactive');

            abort(404, 'Escritório não encontrado.');
        }

        DB::transaction(function () use ($user, $office, $request): void {
            // Sessão SPA + cache por usuário (token clients / suite de testes).
            $this->currentOffice->rememberPlatformSelection($user, $office->id);
            // Persistência do padrão global (sobrevive ao próximo login).
            $this->currentOffice->persistDefaultOffice($user, $office->id);

            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->put(PlatformPrivilegedContext::SESSION_KEY, $office->id);
            }
        });

        $this->currentOffice->clear();
        $this->currentOffice->bindPlatformPrivileged($user, $office);

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $user->id,
            officeId: $office->id,
            action: PlatformPrivilegedAuditEvent::ACTION_SELECT_OFFICE,
            result: PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
            targetType: Office::class,
            targetId: $office->id,
            requestId: $this->requestId($request),
            metadata: [
                'access_mode' => OfficeAccessMode::PlatformPrivileged->value,
                'from_office_id' => $fromOfficeId,
                'to_office_id' => $office->id,
                'membership_created' => false,
                'default_office_id' => $office->id,
                'selected_office_id_unchanged' => $user->selected_office_id,
            ],
        );

        return $office;
    }

    private function hasActiveRealMembership(User $user, int $targetOfficeId): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        return OfficeMembership::query()
            ->where('user_id', $user->id)
            ->where('office_id', $targetOfficeId)
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    /**
     * Remove a seleção privilegiada da sessão (não apaga default_office_id).
     */
    public function clear(User $user, Request $request): void
    {
        if (! $user->is_active || ! $user->isPlatformAdmin()) {
            abort(403, 'Ação restrita a administradores da plataforma.');
        }

        $previousOfficeId = $this->currentOffice->platformSelectedOfficeId($user);
        $this->currentOffice->forgetPlatformSelection($user);
        $this->currentOffice->clear();

        if ($previousOfficeId !== null) {
            PlatformPrivilegedAuditEvent::record(
                actorUserId: $user->id,
                officeId: $previousOfficeId,
                action: PlatformPrivilegedAuditEvent::ACTION_CLEAR_OFFICE,
                result: PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
                targetType: Office::class,
                targetId: $previousOfficeId,
                requestId: $this->requestId($request),
                metadata: [
                    'access_mode' => OfficeAccessMode::PlatformPrivileged->value,
                    'cleared_office_id' => $previousOfficeId,
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
     *     office: array{id: int, name: string|null, slug: string|null}|null,
     *     role: string|null,
     *     real_office_role: string|null,
     *     has_real_membership: bool,
     *     actor_user_id: int|null,
     *     default_office_id: int|null
     * }
     */
    public function current(User $user): array
    {
        $enabled = FeatureFlags::isPlatformPrivilegedContextEnabled();
        $this->currentOffice->clear();
        $office = $enabled && $user->isPlatformAdmin()
            ? $this->currentOffice->resolve($user)
            : null;

        $privileged = $office !== null && $this->currentOffice->isPlatformPrivileged();

        return [
            'enabled' => $enabled,
            'selected' => $privileged,
            'access_mode' => $privileged
                ? OfficeAccessMode::PlatformPrivileged->value
                : $this->currentOffice->accessMode()?->value,
            'office' => $privileged && $office !== null ? [
                'id' => $office->id,
                'name' => $office->name,
                'slug' => $office->slug,
            ] : null,
            'role' => $privileged ? OfficeRole::Admin->value : null,
            'real_office_role' => $privileged ? $this->currentOffice->realOfficeRole()?->value : null,
            'has_real_membership' => $privileged && $this->currentOffice->hasRealMembership(),
            'actor_user_id' => $privileged ? $user->id : null,
            'default_office_id' => $this->currentOffice->defaultOfficeId($user),
        ];
    }

    /**
     * @return array{id: int, name: string|null, slug: string|null, is_active: bool, status: string, lifecycle_status: string, selectable: bool}
     */
    private function summarizeOffice(Office $o): array
    {
        $active = (bool) $o->is_active;
        $lifecycle = $o->lifecycle_status instanceof OfficeLifecycleStatus
            ? $o->lifecycle_status->value
            : (string) ($o->getAttribute('lifecycle_status') ?? 'ACTIVE');

        $lifecycleStatus = $o->lifecycle_status instanceof OfficeLifecycleStatus
            ? $o->lifecycle_status
            : OfficeLifecycleStatus::tryFrom($o->getAttribute('lifecycle_status') ?? 'ACTIVE');

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
            'selectable' => $active && $lifecycleStatus instanceof OfficeLifecycleStatus && $lifecycleStatus->isSelectable(),
        ];
    }

    private function auditDenied(User $user, int $targetOfficeId, string $reason): void
    {
        $officeExists = Office::query()->whereKey($targetOfficeId)->exists();
        if (! $officeExists) {
            return;
        }

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $user->id,
            officeId: $targetOfficeId,
            action: PlatformPrivilegedAuditEvent::ACTION_SELECT_OFFICE,
            result: PlatformPrivilegedAuditEvent::RESULT_DENIED,
            targetType: Office::class,
            targetId: $targetOfficeId,
            requestId: $this->requestId(request()),
            metadata: [
                'reason' => $reason,
                'access_mode' => OfficeAccessMode::PlatformPrivileged->value,
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
