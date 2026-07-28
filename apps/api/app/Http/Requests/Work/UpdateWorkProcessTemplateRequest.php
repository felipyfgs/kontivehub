<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkProcessTemplateData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessTemplateWriter;

final class UpdateWorkProcessTemplateRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $template = $this->route('template');

        return $actor instanceof User
            && $template instanceof WorkProcessTemplate
            && $actor->can('update', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');

        return app(WorkProcessTemplateWriter::class)->rules(
            ignoreId: $template instanceof WorkProcessTemplate ? $template->id : null,
        );
    }

    public function payload(): WorkProcessTemplateData
    {
        return new WorkProcessTemplateData($this->validated());
    }
}
