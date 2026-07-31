<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessLockData;
use App\Models\User;
use App\Models\WorkProcess;

final class ArchiveWorkProcessRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $process = $this->route('process');

        return $actor instanceof User
            && $process instanceof WorkProcess
            && $actor->can('archive', $process);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }

    public function lock(): ProcessLockData
    {
        return new ProcessLockData($this->integer('lock_version'));
    }
}
