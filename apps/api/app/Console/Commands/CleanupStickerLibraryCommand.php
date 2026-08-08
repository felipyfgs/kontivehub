<?php

namespace App\Console\Commands;

use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CleanupStickerLibraryCommand extends Command
{
    protected $signature = 'communication:cleanup-sticker-library {--dry-run : Apenas reporta candidatos} {--chunk=100 : Tamanho do chunk por tenant}';

    protected $description = 'Remove figurinhas sincronizadas expiradas sem referência protegida.';

    public function handle(MediaStore $media): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, min(500, (int) $this->option('chunk')));
        $removedContents = 0;
        $removedObservations = 0;

        CommunicationStickerObservation::query()->withoutGlobalScope('tenant')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('app_favorite', false)
            ->where('device_favorite', false)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$removedObservations, $dryRun): void {
                foreach ($rows as $observation) {
                    if ($dryRun) {
                        $removedObservations++;

                        continue;
                    }
                    $observation->forceFill(['removed_at' => now(), 'app_favorite' => false])->save();
                    $removedObservations++;
                }
            });

        CommunicationStickerContent::query()->withoutGlobalScope('tenant')
            ->where('retention_protected', false)
            ->where(function ($query): void {
                $query->whereNotNull('expires_at')->where('expires_at', '<', now());
            })
            ->whereDoesntHave('observations', function ($query): void {
                $query->whereNull('removed_at')
                    ->orWhere('app_favorite', true)
                    ->orWhere('device_favorite', true);
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$removedContents, $dryRun, $media): void {
                foreach ($rows as $content) {
                    if ($dryRun) {
                        $removedContents++;

                        continue;
                    }
                    DB::transaction(function () use ($content, $media): void {
                        $objectId = (string) $content->object_id_encrypted;
                        $content->delete();
                        if ($objectId !== '') {
                            $media->delete($objectId);
                        }
                    });
                    $removedContents++;
                }
            });

        Log::info('communication.sticker.cleaned', [
            'dry_run' => $dryRun,
            'observations' => $removedObservations,
            'contents' => $removedContents,
        ]);
        $this->info(($dryRun ? 'Dry-run: ' : '')."observations={$removedObservations} contents={$removedContents}");

        return self::SUCCESS;
    }
}
