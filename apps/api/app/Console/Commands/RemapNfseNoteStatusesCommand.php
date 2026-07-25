<?php

namespace App\Console\Commands;

use App\Models\NfseEvent;
use App\Models\NfseNote;
use App\Support\NfseNoteStatus;
use Illuminate\Console\Command;

/**
 * Corrige projeções legadas: cStat 101 marcado como CANCELLED → SUBSTITUTE
 * (salvo se já houver evento de cancelamento na chave).
 */
class RemapNfseNoteStatusesCommand extends Command
{
    protected $signature = 'nfse:remap-statuses
                            {--dry-run : Apenas lista alterações sem gravar}
                            {--office= : Restringe a um office_id}';

    protected $description = 'Remapeia status de NFS-e alinhado ao padrão nacional (cStat 101 → SUBSTITUTE)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $officeId = $this->option('office');

        $query = NfseNote::query()
            ->where('official_status_code', '101')
            ->where('status', 'CANCELLED');

        if ($officeId !== null && $officeId !== '') {
            $query->where('office_id', (int) $officeId);
        }

        $candidates = $query->get();
        $updated = 0;
        $skipped = 0;

        foreach ($candidates as $note) {
            $hasCancelEvent = NfseEvent::query()
                ->where('office_id', $note->office_id)
                ->where('access_key', $note->access_key)
                ->get()
                ->contains(function (NfseEvent $event): bool {
                    return NfseNoteStatus::fromEventType($event->event_type) === NfseNoteStatus::CANCELLED
                        || NfseNoteStatus::fromEventType($event->event_type) === NfseNoteStatus::SUPERSEDED;
                });

            if ($hasCancelEvent) {
                $skipped++;
                $this->line("skip {$note->access_key}: evento de cancelamento presente");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."{$note->access_key}: CANCELLED → SUBSTITUTE (cStat 101)");
            if (! $dry) {
                $note->status = NfseNoteStatus::SUBSTITUTE;
                $note->save();
            }
            $updated++;
        }

        // REPLACED legado → SUPERSEDED
        $replacedQuery = NfseNote::query()->where('status', 'REPLACED');
        if ($officeId !== null && $officeId !== '') {
            $replacedQuery->where('office_id', (int) $officeId);
        }
        $replacedCount = $replacedQuery->count();
        if (! $dry && $replacedCount > 0) {
            $replacedQuery->update(['status' => NfseNoteStatus::SUPERSEDED]);
        }
        if ($replacedCount > 0) {
            $this->line(($dry ? '[dry-run] ' : '')."REPLACED → SUPERSEDED: {$replacedCount}");
        }

        $this->info('Concluído. '.($dry ? 'Dry-run: ' : '')."atualizados={$updated} ignorados={$skipped} replaced_legacy={$replacedCount}");

        return self::SUCCESS;
    }
}
