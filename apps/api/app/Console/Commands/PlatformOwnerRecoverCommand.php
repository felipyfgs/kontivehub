<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Recuperação/transferência do Proprietário (host ops).
 * Senha apenas por prompt oculto — nunca em argv, stdout, log ou auditoria.
 */
class PlatformOwnerRecoverCommand extends Command
{
    protected $signature = 'app:platform-owner:recover
        {--transfer-to= : user_id alvo para transferência (opcional; sem isso corrige o titular atual)}';

    protected $description = 'Recupera ou transfere o Proprietário singleton (host)';

    public function handle(PlatformOwnerService $owners): int
    {
        $pm = $owners->findMembership();
        if ($pm === null) {
            $this->error('Nenhum Proprietário (PLATFORM_ADMIN) na instalação.');

            return self::FAILURE;
        }

        $current = $pm->user;
        $this->info(sprintf(
            'Proprietário atual: user_id=%s email=%s active=%s',
            $pm->user_id,
            $current?->email ?? '—',
            ($current?->is_active && $pm->is_active) ? 'yes' : 'no',
        ));

        $transferTo = $this->option('transfer-to');
        $isTransfer = $transferTo !== null && $transferTo !== '';

        if ($isTransfer) {
            return $this->handleTransfer($owners, (string) $transferTo, $pm->user_id);
        }

        return $this->handleInPlace($owners, $current);
    }

    private function handleInPlace(PlatformOwnerService $owners, ?User $current): int
    {
        $name = $this->ask('Nome', $current?->name);
        $email = $this->ask('E-mail', $current?->email);
        $password = $this->secret('Nova senha (mín. 12 caracteres)');
        $passwordConfirm = $this->secret('Confirme a nova senha');

        if ($password !== $passwordConfirm) {
            $this->error('Senhas não conferem.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $confirm = $this->ask('Confirme digitando RECUPERAR para atualizar o titular atual');
        if ($confirm !== 'RECUPERAR') {
            $this->warn('Cancelado. Nenhum dado foi alterado.');

            return self::FAILURE;
        }

        try {
            $pm = $owners->recoverInPlace(
                (string) $name,
                (string) $email,
                (string) $password,
            );
        } catch (PlatformOwnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Proprietário recuperado: user_id=%d email=%s (sessões revogadas).',
            $pm->user_id,
            $pm->user?->email ?? '—',
        ));

        return self::SUCCESS;
    }

    private function handleTransfer(PlatformOwnerService $owners, string $transferTo, int $currentUserId): int
    {
        if (! ctype_digit($transferTo)) {
            $this->error('--transfer-to deve ser um user_id numérico.');

            return self::FAILURE;
        }

        $targetId = (int) $transferTo;
        $target = User::query()->find($targetId);
        if ($target === null) {
            $this->error("Usuário alvo user_id={$targetId} não encontrado.");

            return self::FAILURE;
        }

        if ($targetId === $currentUserId) {
            $this->error('O usuário-alvo já é o Proprietário. Use recuperação in-place.');

            return self::FAILURE;
        }

        $this->info(sprintf('Alvo: user_id=%d email=%s', $target->id, $target->email));

        $setPassword = $this->confirm('Definir nova senha para o alvo?', true);
        $password = null;
        if ($setPassword) {
            $password = $this->secret('Nova senha do alvo (mín. 12 caracteres)');
            $passwordConfirm = $this->secret('Confirme a nova senha');
            if ($password !== $passwordConfirm) {
                $this->error('Senhas não conferem.');

                return self::FAILURE;
            }
            if (strlen((string) $password) < 12) {
                $this->error('Senha deve ter no mínimo 12 caracteres.');

                return self::FAILURE;
            }
        }

        $confirm = $this->ask(
            'Confirme digitando TRANSFERIR para mover o vínculo global para user_id='.$targetId,
        );
        if ($confirm !== 'TRANSFERIR') {
            $this->warn('Cancelado. Nenhum dado foi alterado.');

            return self::FAILURE;
        }

        try {
            $pm = $owners->transferTo($target, $password);
        } catch (PlatformOwnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Propriedade transferida: user_id=%d email=%s (sessões de ambos revogadas; 1 PLATFORM_ADMIN).',
            $pm->user_id,
            $pm->user?->email ?? '—',
        ));

        return self::SUCCESS;
    }
}
