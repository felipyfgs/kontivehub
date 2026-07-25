<?php

namespace App\Console\Commands;

use App\Contracts\SecureObjectStore;
use App\Models\VaultObjectJournalEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Vault\EnvelopeCrypto;
use App\Services\Vault\FilesystemSecureObjectStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Rewrap retomável/idempotente de objetos do vault para a master key atual.
 * Não imprime plaintext; dry-run verifica open+seal sem gravar.
 */
class VaultRewrapCommand extends Command
{
    protected $signature = 'vault:rewrap
        {--dry-run : Verifica sem reescrever envelopes}
        {--limit=0 : Máximo de objetos a processar (0 = todos)}
        {--purpose= : Filtra journal por purpose (opcional)}
        {--object= : Reprocessa um object_id específico}';

    protected $description = 'Re-cifra objetos do vault com a chave mestra atual (keyring)';

    public function handle(SecureObjectStore $store, EnvelopeCrypto $crypto, AuditLogger $audit): int
    {
        if (! $store instanceof FilesystemSecureObjectStore) {
            $this->error('Rewrap requer FilesystemSecureObjectStore.');

            return self::FAILURE;
        }

        $lock = Cache::lock('vault:rewrap', 3600);
        if (! $lock->get()) {
            $this->error('Outro rewrap em andamento (lock vault:rewrap).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $target = $this->option('object') ? strtoupper((string) $this->option('object')) : null;

        try {
            $ids = $target !== null
                ? [$target]
                : $store->listObjectIds();

            $processed = 0;
            $rewritten = 0;
            $skipped = 0;
            $failed = 0;
            $current = $crypto->currentKeyVersion();

            $this->info(sprintf(
                'Vault rewrap — current_key_version=%d dry_run=%s objects=%d',
                $current,
                $dryRun ? 'yes' : 'no',
                count($ids)
            ));

            foreach ($ids as $id) {
                if ($limit > 0 && $processed >= $limit) {
                    break;
                }

                $metadata = $this->resolveMetadata($id);
                try {
                    $from = $store->cryptoKeyVersionOf($id);
                    if ($from === $current && $target === null) {
                        $skipped++;
                        $processed++;

                        continue;
                    }

                    $result = $store->rewrap($id, $metadata, $dryRun);
                    $processed++;
                    if ($result['rewritten']) {
                        $rewritten++;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $processed++;
                    $this->warn("FAIL {$id}: ".$this->sanitize($e->getMessage()));
                }
            }

            $audit->record('vault.rewrap', $failed > 0 ? 'PARTIAL' : 'SUCCESS', null, [
                'dry_run' => $dryRun,
                'processed' => $processed,
                'rewritten' => $rewritten,
                'skipped' => $skipped,
                'failed' => $failed,
                'current_key_version' => $current,
            ], null, null);

            $this->table(
                ['metric', 'value'],
                [
                    ['processed', $processed],
                    ['rewritten', $rewritten],
                    ['skipped', $skipped],
                    ['failed', $failed],
                    ['current_key_version', $current],
                ]
            );

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function resolveMetadata(string $objectId): array
    {
        $entry = VaultObjectJournalEntry::query()->where('object_id', $objectId)->first();
        if ($entry === null) {
            // Sem journal: rewrap exige AAD correto; falha explícita se necessário no rewrap.
            return ['purpose' => 'UNKNOWN'];
        }

        return [
            'purpose' => $entry->purpose,
            'office_id' => $entry->office_id,
        ];
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/[A-Za-z0-9+\/]{40,}={0,2}/', '[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 200);
    }
}
