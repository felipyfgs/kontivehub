<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Console\Command;

/**
 * Consolida múltiplos PLATFORM_ADMIN legados em um único titular (--keep).
 * Não remove usuários nem OfficeMembership; só vínculos globais excedentes.
 */
class PlatformOwnerConsolidateCommand extends Command
{
    protected $signature = 'app:platform-owner:consolidate
        {--keep= : user_id do Proprietário a preservar}';

    protected $description = 'Consolida PLATFORM_ADMIN legados em um único Proprietário (explícito)';

    public function handle(PlatformOwnerService $owners): int
    {
        $keepRaw = $this->option('keep');
        if ($keepRaw === null || $keepRaw === '' || ! ctype_digit((string) $keepRaw)) {
            $this->error('Informe --keep=<user-id> numérico do titular a preservar.');

            return self::FAILURE;
        }

        $keepUserId = (int) $keepRaw;

        $rows = PlatformMembership::query()
            ->with('user')
            ->where('role', PlatformRole::PlatformAdmin)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->error('Nenhum PLATFORM_ADMIN encontrado.');

            return self::FAILURE;
        }

        $this->table(
            ['membership_id', 'user_id', 'email', 'is_active'],
            $rows->map(fn (PlatformMembership $pm) => [
                $pm->id,
                $pm->user_id,
                $pm->user?->email ?? '—',
                $pm->is_active ? 'yes' : 'no',
            ])->all(),
        );

        if ($rows->count() === 1) {
            $only = $rows->first();
            if ((int) $only->user_id === $keepUserId) {
                $this->info('Já existe exatamente um PLATFORM_ADMIN (user_id='.$keepUserId.'). Nada a fazer.');

                return self::SUCCESS;
            }

            $this->error("O único PLATFORM_ADMIN é user_id={$only->user_id}, não {$keepUserId}.");

            return self::FAILURE;
        }

        if (! $rows->contains(fn (PlatformMembership $pm) => (int) $pm->user_id === $keepUserId)) {
            $this->error("user-id={$keepUserId} não possui PlatformMembership PLATFORM_ADMIN.");

            return self::FAILURE;
        }

        $keepUser = User::query()->find($keepUserId);
        $confirm = $this->ask(
            'Confirme digitando CONSOLIDAR para remover '
            .($rows->count() - 1)
            .' vínculo(s) excedente(s) e manter user_id='.$keepUserId
            .' ('.($keepUser?->email ?? '?').')',
        );

        if ($confirm !== 'CONSOLIDAR') {
            $this->warn('Cancelado. Nenhum dado foi alterado.');

            return self::FAILURE;
        }

        try {
            $result = $owners->consolidate($keepUserId);
        } catch (PlatformOwnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Consolidado: kept_user_id=%d, removidos=%d, sessões revogadas=%d.',
            $result['kept_user_id'],
            count($result['removed_membership_ids']),
            count($result['revoked_user_ids']),
        ));

        return self::SUCCESS;
    }
}
