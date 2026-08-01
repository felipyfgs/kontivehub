<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessTemplateData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessTemplateWriter;

final class UpdateProcessTemplateRequest extends TenantScopedRequest
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

        return app(ProcessTemplateWriter::class)->rules(
            ignoreId: $template instanceof WorkProcessTemplate ? $template->id : null,
        );
    }

    public function payload(): ProcessTemplateData
    {
        return new ProcessTemplateData($this->validated());
    }
}
