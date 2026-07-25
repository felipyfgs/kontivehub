<?php

namespace App\Console\Commands;

use App\Models\Export;
use Illuminate\Console\Command;

class PurgeExpiredExportsCommand extends Command
{
    protected $signature = 'exports:purge-expired';

    protected $description = 'Remove ZIPs de exportação expirados';

    public function handle(): int
    {
        $count = 0;
        Export::query()
            ->where('status', 'READY')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($exports) use (&$count): void {
                foreach ($exports as $export) {
                    if ($export->storage_path && is_file($export->storage_path)) {
                        @unlink($export->storage_path);
                    }
                    // Manifesto de ausências (export mensal) — só se sob o office do export
                    $manifest = is_array($export->filters)
                        ? ($export->filters['absence_manifest_path'] ?? null)
                        : null;
                    if (is_string($manifest) && $manifest !== '') {
                        $root = realpath(storage_path('app/private/exports/'.$export->office_id));
                        $real = realpath($manifest);
                        if ($root !== false && $real !== false
                            && (str_starts_with($real, $root.DIRECTORY_SEPARATOR) || $real === $root)
                            && is_file($real)) {
                            @unlink($real);
                        }
                    }
                    $export->status = 'EXPIRED';
                    $export->storage_path = null;
                    $export->save();
                    $count++;
                }
            });

        $this->info("Expirados: {$count}");

        return self::SUCCESS;
    }
}
