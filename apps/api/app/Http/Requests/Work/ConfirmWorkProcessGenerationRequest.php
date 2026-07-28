<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;

final class ConfirmWorkProcessGenerationRequest extends WorkRequest
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
            && $actor->can('viewAny', WorkProcessTemplate::class)
            && $actor->can('generate', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        return $this->validated('idempotency_key');
    }
}
