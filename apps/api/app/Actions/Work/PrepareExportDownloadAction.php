<?php

namespace App\Actions\Work;

use App\DTO\Work\ExportDownloadData;
use App\Enums\Work\WorkExportStatus;
use App\Models\WorkExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PrepareExportDownloadAction
{
    public function execute(WorkExport $export): ExportDownloadData
    {
        $storagePath = $export->storage_path;
        if ($export->status !== WorkExportStatus::Ready
            || ! is_string($storagePath)
            || $storagePath === ''
            || ! Storage::disk('local')->exists($storagePath)) {
            throw new NotFoundHttpException;
        }

        return new ExportDownloadData(
            storagePath: $storagePath,
            filename: 'work-export-'.$export->id.'.csv',
        );
    }
}
