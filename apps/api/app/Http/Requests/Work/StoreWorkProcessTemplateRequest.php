<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkProcessTemplateData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessTemplateWriter;

final class StoreWorkProcessTemplateRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return app(WorkProcessTemplateWriter::class)->rules();
    }

    public function payload(): WorkProcessTemplateData
    {
        return new WorkProcessTemplateData($this->validated());
    }
}
