<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Http\UploadedFile;

final class UploadWorkTaskEvidenceRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('uploadEvidence', $task);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:20480']];
    }

    public function evidenceFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
