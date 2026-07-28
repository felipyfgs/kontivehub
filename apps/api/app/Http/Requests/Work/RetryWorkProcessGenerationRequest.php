<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;

final class RetryWorkProcessGenerationRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $batch = $this->route('batch');
        if (! $actor instanceof User || ! $batch instanceof WorkProcessGenerationBatch) {
            return false;
        }

        $template = WorkProcessTemplate::query()->find($batch->work_process_template_id);

        return $template instanceof WorkProcessTemplate
            && $actor->can('retryGeneration', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
