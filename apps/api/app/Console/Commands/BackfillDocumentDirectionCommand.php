<?php

namespace App\Console\Commands;

use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Models\NfeDocument;
use App\Models\NfseNote;
use Illuminate\Console\Command;

/**
 * Re-deriva direction a partir de fiscal_role em projeções legadas.
 */
class BackfillDocumentDirectionCommand extends Command
{
    protected $signature = 'documents:backfill-direction
                            {--dry-run : Apenas lista contagens sem gravar}
                            {--office= : Restringe a um office_id}';

    protected $description = 'Backfill de direction (IN|OUT|UNKNOWN) a partir de fiscal_role';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $officeId = $this->option('office');

        $nfseUpdated = $this->backfillNfse($dry, $officeId);
        $nfeUpdated = $this->backfillNfe($dry, $officeId);

        $this->info(
            ($dry ? '[dry-run] ' : '').
            "Concluído. nfse_notes={$nfseUpdated} nfe_documents={$nfeUpdated}"
        );

        return self::SUCCESS;
    }

    private function backfillNfse(bool $dry, mixed $officeId): int
    {
        $query = NfseNote::query();
        if ($officeId !== null && $officeId !== '') {
            $query->where('office_id', (int) $officeId);
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(200, function ($notes) use ($dry, &$updated): void {
            foreach ($notes as $note) {
                $role = $note->fiscal_role instanceof FiscalRole
                    ? $note->fiscal_role
                    : FiscalRole::tryFrom((string) $note->fiscal_role);
                $direction = DocumentDirection::fromFiscalRole($role);
                if ($note->direction === $direction) {
                    continue;
                }
                $updated++;
                if (! $dry) {
                    $note->direction = $direction;
                    $note->save();
                }
            }
        });

        return $updated;
    }

    private function backfillNfe(bool $dry, mixed $officeId): int
    {
        $query = NfeDocument::query();
        if ($officeId !== null && $officeId !== '') {
            $query->where('office_id', (int) $officeId);
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(200, function ($docs) use ($dry, &$updated): void {
            foreach ($docs as $doc) {
                $role = $doc->fiscal_role instanceof FiscalRole
                    ? $doc->fiscal_role
                    : FiscalRole::tryFrom((string) $doc->fiscal_role);
                // DistDFe sem papel: entrada (interesse não-emitente)
                $direction = $role === null
                    ? DocumentDirection::In
                    : DocumentDirection::fromFiscalRole($role);
                if ($doc->direction === $direction) {
                    continue;
                }
                $updated++;
                if (! $dry) {
                    $doc->direction = $direction;
                    $doc->save();
                }
            }
        });

        return $updated;
    }
}
