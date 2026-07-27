<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Troca explícita de tenant entre memberships ativas.
 * Nunca confia tenant_id como autoridade sem revalidar membership.
 *
 * Persistência: `users.selected_tenant_id` (durável) + sessão SPA quando disponível.
 */
final class TenantSwitchService
{
    public const SESSION_KEY = CurrentTenant::SESSION_KEY;

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403/404
     */
    public function switchTo(User $user, int $targetTenantId, Request $request): Tenant
    {
        if (! $user->is_active) {
            abort(403, 'Usuário inativo.');
        }

        $fromTenantId = $this->currentTenant->resolve($user)?->id;

        $membership = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $targetTenantId)
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->with('tenant')
            ->first();

        // Não revelar existência do tenant alvo sem membership.
        if ($membership === null || $membership->tenant === null) {
            $this->audit->record(
                action: 'tenant.switch_denied',
                result: 'DENIED',
                context: [
                    'from_tenant_id' => $fromTenantId,
                    'reason' => 'no_active_membership',
                ],
                userId: $user->id,
                tenantId: $fromTenantId,
            );

            abort(404, 'Escritório não encontrado.');
        }

        $tenant = $membership->tenant;

        // Preferência durável (funciona sem sessão SPA / token / testes).
        $user->forceFill(['selected_tenant_id' => $tenant->id])->save();

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $tenant->id);
            // Rotaciona id de sessão sem destroy (mantém atributos no driver array).
            $request->session()->regenerate();
        }

        $this->currentTenant->clear();
        $this->currentTenant->bind($user, $membership);

        $this->audit->record(
            action: 'tenant.switched',
            result: 'SUCCESS',
            subject: $tenant,
            context: [
                'from_tenant_id' => $fromTenantId,
                'to_tenant_id' => $tenant->id,
            ],
            userId: $user->id,
            tenantId: $tenant->id,
        );

        return $tenant;
    }

    /**
     * Lista memberships ativas do usuário (sem conteúdo fiscal).
     *
     * @return list<array{tenant_id: int, tenant_name: string|null, tenant_slug: string|null, role: string, is_current: bool}>
     */
    public function listMemberships(User $user): array
    {
        $currentId = $this->currentTenant->resolve($user)?->id;

        return $user->memberships()
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->with('tenant')
            ->orderBy('id')
            ->get()
            ->map(fn (TenantMembership $m) => [
                'tenant_id' => $m->tenant_id,
                'tenant_name' => $m->tenant?->name,
                'tenant_slug' => $m->tenant?->slug,
                'role' => $m->role->value,
                'is_current' => $currentId !== null && $m->tenant_id === $currentId,
            ])
            ->values()
            ->all();
    }
}
