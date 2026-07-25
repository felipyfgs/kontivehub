<?php

namespace App\Console\Commands;

use App\Services\Certificates\CredentialService;
use Illuminate\Console\Command;

class RefreshCredentialExpiryCommand extends Command
{
    protected $signature = 'credentials:refresh-expiry';

    protected $description = 'Atualiza alertas de vencimento de A1, expira credenciais e bloqueia cursores dependentes';

    public function handle(CredentialService $credentials): int
    {
        $result = $credentials->refreshExpiryAlerts();

        $this->info(sprintf(
            'Credenciais atualizadas: %d | cursores bloqueados: %d',
            $result['credentials'],
            $result['cursors_blocked'],
        ));

        return self::SUCCESS;
    }
}
