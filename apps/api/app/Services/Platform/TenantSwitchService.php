<?php

namespace App\Services\Platform;

use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Troca explícita de tenant entre memberships ativas.
 * Nunca confia office_id como autoridade sem revalidar membership.
 *
 * Persistência: `users.selected_office_id` (durável) + sessão SPA quando disponível.
 */
final class TenantSwitchService
{
    public const SESSION_KEY = CurrentOffice::SESSION_KEY;

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403/404
     */
    public function switchTo(User $user, int $targetOfficeId, Request $request): Office
    {
        if (! $user->is_active) {
            abort(403, 'Usuário inativo.');
        }

        $fromOfficeId = $this->currentOffice->resolve($user)?->id;

        $membership = OfficeMembership::query()
            ->where('user_id', $user->id)
            ->where('office_id', $targetOfficeId)
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q->where('is_active', true))
            ->with('office')
            ->first();

        // Não revelar existência do tenant alvo sem membership.
        if ($membership === null || $membership->office === null) {
            $this->audit->record(
                action: 'tenant.switch_denied',
                result: 'DENIED',
                context: [
                    'from_office_id' => $fromOfficeId,
                    'reason' => 'no_active_membership',
                ],
                userId: $user->id,
                officeId: $fromOfficeId,
            );

            abort(404, 'Escritório não encontrado.');
        }

        $office = $membership->office;

        // Preferência durável (funciona sem sessão SPA / token / testes).
        $user->forceFill(['selected_office_id' => $office->id])->save();

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $office->id);
            // Rotaciona id de sessão sem destroy (mantém atributos no driver array).
            $request->session()->regenerate();
        }

        $this->currentOffice->clear();
        $this->currentOffice->bind($user, $membership);

        $this->audit->record(
            action: 'tenant.switched',
            result: 'SUCCESS',
            subject: $office,
            context: [
                'from_office_id' => $fromOfficeId,
                'to_office_id' => $office->id,
            ],
            userId: $user->id,
            officeId: $office->id,
        );

        return $office;
    }

    /**
     * Lista memberships ativas do usuário (sem conteúdo fiscal).
     *
     * @return list<array{office_id: int, office_name: string|null, office_slug: string|null, role: string, is_current: bool}>
     */
    public function listMemberships(User $user): array
    {
        $currentId = $this->currentOffice->resolve($user)?->id;

        return $user->memberships()
            ->where('is_active', true)
            ->whereHas('office', fn ($q) => $q->where('is_active', true))
            ->with('office')
            ->orderBy('id')
            ->get()
            ->map(fn (OfficeMembership $m) => [
                'office_id' => $m->office_id,
                'office_name' => $m->office?->name,
                'office_slug' => $m->office?->slug,
                'role' => $m->role->value,
                'is_current' => $currentId !== null && $m->office_id === $currentId,
            ])
            ->values()
            ->all();
    }
}
