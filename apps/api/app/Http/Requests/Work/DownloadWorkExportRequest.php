<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkExport;

final class DownloadWorkExportRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $export = $this->route('export');

        return $actor instanceof User
            && $export instanceof WorkExport
            && $actor->can('download', $export);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
