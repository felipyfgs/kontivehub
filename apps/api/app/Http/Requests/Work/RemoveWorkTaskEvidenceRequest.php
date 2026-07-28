<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkEvidenceRemovalData;
use App\Models\User;
use App\Models\WorkTask;

final class RemoveWorkTaskEvidenceRequest extends WorkRequest
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
        return ['reason' => ['required', 'string', 'max:2000']];
    }

    public function removal(): WorkEvidenceRemovalData
    {
        return new WorkEvidenceRemovalData(
            (string) $this->validated('reason'),
        );
    }
}
