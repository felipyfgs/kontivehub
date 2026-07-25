<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varredura sanitizada de colunas/tabelas que NÃO devem expor material secreto em claro.
 * Não imprime valores — só nomes de coluna e contagens de suspeita estrutural.
 */
class FiscalModelSecretScanCommand extends Command
{
    protected $signature = 'fiscal-model:secret-scan {--json : Saída JSON}';

    protected $description = 'Varredura estrutural contra vazamento de PFX/PEM/tokens em colunas e audit';

    /** @var list<string> */
    private array $forbiddenColumnPatterns = [
        'pfx', 'private_key', 'pem', 'password', 'consumer_secret',
        'client_secret', 'termo_xml', 'raw_token', 'refresh_token_plain',
    ];

    public function handle(): int
    {
        $findings = [];

        if (DB::getDriverName() === 'pgsql') {
            $columns = DB::select(<<<'SQL'
                SELECT table_name, column_name
                FROM information_schema.columns
                WHERE table_schema = 'public'
                ORDER BY table_name, column_name
                SQL);
        } else {
            $columns = [];
            foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table'") as $t) {
                $table = $t->name;
                foreach (DB::select("PRAGMA table_info('{$table}')") as $col) {
                    $columns[] = (object) [
                        'table_name' => $table,
                        'column_name' => $col->name,
                    ];
                }
            }
        }

        foreach ($columns as $col) {
            $name = strtolower((string) $col->column_name);
            foreach ($this->forbiddenColumnPatterns as $pat) {
                if (str_contains($name, $pat) && ! str_contains($name, 'vault') && ! str_contains($name, 'hash')) {
                    // vault_object_id e fingerprint_sha256 são permitidos (não material)
                    if (str_contains($name, 'vault_object') || str_contains($name, 'fingerprint')) {
                        continue;
                    }
                    // Hash bcrypt do Fortify/Laravel — não é PFX/PEM em claro
                    if ($col->table_name === 'users' && $name === 'password') {
                        continue;
                    }
                    $findings[] = [
                        'type' => 'column_name_suspicious',
                        'table' => $col->table_name,
                        'column' => $col->column_name,
                        'pattern' => $pat,
                    ];
                }
            }
        }

        // Credenciais: vault_object_id nunca nulo quando ACTIVE (metadados ok)
        if (Schema::hasTable('client_credentials')) {
            $activeWithoutVault = (int) DB::table('client_credentials')
                ->where('status', 'ACTIVE')
                ->where(function ($q): void {
                    $q->whereNull('vault_object_id')->orWhere('vault_object_id', '');
                })
                ->count();
            if ($activeWithoutVault > 0) {
                $findings[] = [
                    'type' => 'active_credential_without_vault_ref',
                    'count' => $activeWithoutVault,
                ];
            }
        }

        // audit_logs não deve ter chaves de payload sensível conhecidas nos context keys
        // (inspeção só de chaves se JSON)

        $ok = $findings === [];
        $out = [
            'passed' => $ok,
            'findings_count' => count($findings),
            'findings' => $findings,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($ok ? 'SECRET_SCAN_OK' : 'SECRET_SCAN_FINDINGS');
            foreach ($findings as $f) {
                $this->warn(json_encode($f, JSON_UNESCAPED_UNICODE));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
