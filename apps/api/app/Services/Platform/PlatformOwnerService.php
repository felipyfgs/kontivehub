<?php

namespace App\Services\Platform;

use App\Enums\PlatformRole;
use App\Models\Office;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Domínio do Proprietário singleton (única PlatformMembership PLATFORM_ADMIN).
 * Banco é a autoridade final (índice parcial); este serviço antecipa conflitos
 * e converte colisões concorrentes em platform_owner_already_exists.
 */
final class PlatformOwnerService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function exists(): bool
    {
        return PlatformMembership::query()
            ->where('role', PlatformRole::PlatformAdmin)
            ->exists();
    }

    public function count(): int
    {
        return (int) PlatformMembership::query()
            ->where('role', PlatformRole::PlatformAdmin)
            ->count();
    }

    public function findMembership(): ?PlatformMembership
    {
        return PlatformMembership::query()
            ->with(['user', 'defaultOffice'])
            ->where('role', PlatformRole::PlatformAdmin)
            ->orderBy('id')
            ->first();
    }

    /**
     * @throws PlatformOwnerException
     */
    public function requireMembership(): PlatformMembership
    {
        $pm = $this->findMembership();
        if ($pm === null) {
            throw PlatformOwnerException::notFound();
        }

        return $pm;
    }

    /**
     * @throws PlatformOwnerException
     */
    public function assertSlotAvailable(): void
    {
        if ($this->exists()) {
            throw PlatformOwnerException::alreadyExists();
        }
    }

    /**
     * Cria o único vínculo global para o usuário. Transacional + lock.
     *
     * @throws PlatformOwnerException
     */
    public function createOwner(
        User $user,
        bool $isActive = true,
        ?int $defaultOfficeId = null,
    ): PlatformMembership {
        try {
            return DB::transaction(function () use ($user, $isActive, $defaultOfficeId): PlatformMembership {
                // Serializa criação: trava qualquer linha PLATFORM_ADMIN existente.
                $existing = PlatformMembership::query()
                    ->where('role', PlatformRole::PlatformAdmin)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw PlatformOwnerException::alreadyExists();
                }

                if ($defaultOfficeId !== null) {
                    $this->assertActiveOffice($defaultOfficeId);
                }

                return PlatformMembership::query()->create([
                    'user_id' => $user->id,
                    'role' => PlatformRole::PlatformAdmin,
                    'is_active' => $isActive,
                    'default_office_id' => $defaultOfficeId,
                ]);
            }, 3);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw PlatformOwnerException::alreadyExists(previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Atualiza identidade e/ou Office padrão do titular existente — nunca cria outro vínculo.
     *
     * @param  array{name?: string, email?: string, default_office_id?: int|null}  $input
     * @return array{membership: PlatformMembership, user: User}
     *
     * @throws PlatformOwnerException
     */
    public function updateOwner(array $input, ?User $actor = null): array
    {
        return DB::transaction(function () use ($input, $actor): array {
            $pm = PlatformMembership::query()
                ->where('role', PlatformRole::PlatformAdmin)
                ->lockForUpdate()
                ->first();

            if ($pm === null) {
                throw PlatformOwnerException::notFound();
            }

            /** @var User $user */
            $user = User::query()->whereKey($pm->user_id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $input)) {
                $name = trim((string) $input['name']);
                if ($name === '') {
                    throw PlatformOwnerException::invalid('Nome é obrigatório.');
                }
                $user->name = $name;
            }

            if (array_key_exists('email', $input)) {
                $email = Str::lower(trim((string) $input['email']));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw PlatformOwnerException::invalid('E-mail inválido.');
                }
                if ($email !== Str::lower((string) $user->email)
                    && User::query()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                    throw PlatformOwnerException::invalid(
                        'Não foi possível concluir com o e-mail informado.',
                        'email_unavailable',
                    );
                }
                $user->email = $email;
            }

            if (array_key_exists('default_office_id', $input)) {
                $officeId = $input['default_office_id'];
                if ($officeId !== null) {
                    $this->assertActiveOffice((int) $officeId);
                    $pm->default_office_id = (int) $officeId;
                } else {
                    $pm->default_office_id = null;
                }
            }

            $user->save();
            $pm->save();

            $this->audit->record(
                action: 'platform_owner.updated',
                result: 'SUCCESS',
                subject: $user,
                context: [
                    'user_id' => $user->id,
                    'email_masked' => $this->maskEmail((string) $user->email),
                    'default_office_id' => $pm->default_office_id,
                    'fields' => array_keys($input),
                ],
                userId: $actor?->id ?? $user->id,
            );

            $pm->load(['user', 'defaultOffice']);

            return ['membership' => $pm, 'user' => $user->fresh()];
        });
    }

    /**
     * Corrige nome/e-mail/senha do titular atual (host ops).
     * Senha já deve vir hasheável via cast do model (string plain).
     *
     * @throws PlatformOwnerException
     */
    public function recoverInPlace(
        string $name,
        string $email,
        string $plainPassword,
    ): PlatformMembership {
        return DB::transaction(function () use ($name, $email, $plainPassword): PlatformMembership {
            $pm = PlatformMembership::query()
                ->where('role', PlatformRole::PlatformAdmin)
                ->lockForUpdate()
                ->first();

            if ($pm === null) {
                throw PlatformOwnerException::notFound();
            }

            /** @var User $user */
            $user = User::query()->whereKey($pm->user_id)->lockForUpdate()->firstOrFail();

            $normalizedEmail = Str::lower(trim($email));
            if ($normalizedEmail !== Str::lower((string) $user->email)
                && User::query()->where('email', $normalizedEmail)->where('id', '!=', $user->id)->exists()) {
                throw PlatformOwnerException::invalid(
                    'E-mail já em uso por outra conta.',
                    'email_unavailable',
                );
            }

            $user->forceFill([
                'name' => trim($name),
                'email' => $normalizedEmail,
                'password' => $plainPassword,
                'is_active' => true,
                'password_change_required' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            if (! $pm->is_active) {
                $pm->forceFill(['is_active' => true])->save();
            }

            $this->revokeSessions($user);

            $this->audit->record(
                action: 'platform_owner.recovered_in_place',
                result: 'SUCCESS',
                subject: $user,
                context: [
                    'user_id' => $user->id,
                    'email_masked' => $this->maskEmail($normalizedEmail),
                    'password_set' => true,
                ],
                userId: null,
            );

            return $pm->fresh(['user', 'defaultOffice']);
        });
    }

    /**
     * Transfere a única PlatformMembership para outro usuário atomicamente.
     *
     * @throws PlatformOwnerException
     */
    public function transferTo(User $target, ?string $plainPassword = null): PlatformMembership
    {
        return DB::transaction(function () use ($target, $plainPassword): PlatformMembership {
            $pm = PlatformMembership::query()
                ->where('role', PlatformRole::PlatformAdmin)
                ->lockForUpdate()
                ->first();

            if ($pm === null) {
                throw PlatformOwnerException::notFound();
            }

            /** @var User $previous */
            $previous = User::query()->whereKey($pm->user_id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedTarget */
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ((int) $previous->id === (int) $lockedTarget->id) {
                throw PlatformOwnerException::invalid(
                    'O usuário-alvo já é o Proprietário.',
                    'platform_owner_same_user',
                );
            }

            $previousUserId = (int) $previous->id;

            $pm->forceFill([
                'user_id' => $lockedTarget->id,
                'is_active' => true,
            ])->save();

            $fill = [
                'is_active' => true,
                'password_change_required' => false,
                'email_verified_at' => $lockedTarget->email_verified_at ?? now(),
            ];
            if ($plainPassword !== null && $plainPassword !== '') {
                $fill['password'] = $plainPassword;
            }
            $lockedTarget->forceFill($fill)->save();

            $this->revokeSessions($previous);
            $this->revokeSessions($lockedTarget);

            $this->audit->record(
                action: 'platform_owner.transferred',
                result: 'SUCCESS',
                subject: $lockedTarget,
                context: [
                    'previous_user_id' => $previousUserId,
                    'new_user_id' => $lockedTarget->id,
                    'previous_email_masked' => $this->maskEmail((string) $previous->email),
                    'new_email_masked' => $this->maskEmail((string) $lockedTarget->email),
                    'password_set' => $plainPassword !== null && $plainPassword !== '',
                ],
                userId: null,
            );

            return $pm->fresh(['user', 'defaultOffice']);
        });
    }

    /**
     * Remove vínculos PLATFORM_ADMIN excedentes, mantendo --keep.
     * Preserva usuários e OfficeMembership dos afetados.
     *
     * @return array{kept_user_id: int, removed_membership_ids: list<int>, revoked_user_ids: list<int>}
     *
     * @throws PlatformOwnerException
     */
    public function consolidate(int $keepUserId): array
    {
        return DB::transaction(function () use ($keepUserId): array {
            $rows = PlatformMembership::query()
                ->where('role', PlatformRole::PlatformAdmin)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                throw PlatformOwnerException::notFound('Nenhum PLATFORM_ADMIN para consolidar.');
            }

            $keep = $rows->firstWhere('user_id', $keepUserId);
            if ($keep === null) {
                throw PlatformOwnerException::invalid(
                    "user-id={$keepUserId} não possui PlatformMembership PLATFORM_ADMIN.",
                    'platform_owner_keep_not_found',
                    422,
                );
            }

            $removedIds = [];
            $revokedUserIds = [];

            foreach ($rows as $row) {
                if ((int) $row->id === (int) $keep->id) {
                    continue;
                }

                $userId = (int) $row->user_id;
                $removedIds[] = (int) $row->id;

                // Bypass do guard de exclusão do singleton (operação host explícita).
                PlatformMembership::withoutEvents(function () use ($row): void {
                    $row->delete();
                });

                $user = User::query()->find($userId);
                if ($user !== null) {
                    $this->revokeSessions($user);
                    $revokedUserIds[] = $userId;
                }
            }

            $this->audit->record(
                action: 'platform_owner.consolidated',
                result: 'SUCCESS',
                subject: User::query()->find($keepUserId),
                context: [
                    'kept_user_id' => $keepUserId,
                    'removed_membership_ids' => $removedIds,
                    'revoked_user_ids' => $revokedUserIds,
                    'final_count' => 1,
                ],
                userId: null,
            );

            return [
                'kept_user_id' => $keepUserId,
                'removed_membership_ids' => $removedIds,
                'revoked_user_ids' => $revokedUserIds,
            ];
        });
    }

    public function assertUserMayBeDeleted(User $user): void
    {
        $isOwner = PlatformMembership::query()
            ->where('user_id', $user->id)
            ->where('role', PlatformRole::PlatformAdmin)
            ->exists();

        if ($isOwner) {
            throw PlatformOwnerException::cannotRemove();
        }
    }

    public function assertMembershipMayBeDeleted(PlatformMembership $membership): void
    {
        if ($membership->role === PlatformRole::PlatformAdmin) {
            throw PlatformOwnerException::cannotRemove();
        }
    }

    /**
     * Desativação que apagaria o único caminho de recuperação: bloqueada se
     * tentar remover o vínculo. is_active=false no user/membership é permitido
     * (slot permanece ocupado); remoção do vínculo não.
     */
    public function assertMembershipMayBeDeactivated(PlatformMembership $membership): void
    {
        // Slot singleton permanece com is_active=false; não bloqueamos desativar.
        // Remoção física é o que assertMembershipMayBeDeleted cobre.
    }

    public function revokeSessions(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * Payload sanitizado para API GET/PATCH owner.
     *
     * @return array<string, mixed>
     */
    public function sanitize(PlatformMembership $pm): array
    {
        $pm->loadMissing(['user', 'defaultOffice']);
        $user = $pm->user;

        return [
            'user_id' => $user?->id ?? $pm->user_id,
            'name' => $user?->name,
            'email' => $user?->email,
            'is_active' => (bool) ($user?->is_active && $pm->is_active),
            'membership_active' => (bool) $pm->is_active,
            'password_change_required' => (bool) $user?->password_change_required,
            'default_office_id' => $pm->default_office_id,
            'default_office' => $pm->defaultOffice === null ? null : [
                'id' => $pm->defaultOffice->id,
                'name' => $pm->defaultOffice->name,
                'slug' => $pm->defaultOffice->slug,
            ],
            'created_at' => $pm->created_at?->toIso8601String(),
        ];
    }

    private function assertActiveOffice(int $officeId): void
    {
        $ok = Office::query()
            ->whereKey($officeId)
            ->where('is_active', true)
            ->exists();

        if (! $ok) {
            throw PlatformOwnerException::invalid('Office padrão inválido ou inativo.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = $e->getMessage();

        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'platform_memberships_one_platform_admin')
            || str_contains($message, 'duplicate key');
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $prefix = strlen($local) <= 1 ? '*' : substr($local, 0, 1).'***';

        return $prefix.'@'.$domain;
    }
}
