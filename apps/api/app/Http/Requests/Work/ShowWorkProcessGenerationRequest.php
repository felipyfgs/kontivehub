<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;

final class ShowWorkProcessGenerationRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $batch = $this->route('batch');

        return $actor instanceof User
            && $batch instanceof WorkProcessGenerationBatch
            && $actor->can('viewAny', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
