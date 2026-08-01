<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessTemplateRecurrenceData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessTemplateRecurrenceService;

final class UpdateProcessTemplateRecurrenceRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $template = $this->route('template');

        return $actor instanceof User
            && $template instanceof WorkProcessTemplate
            && $actor->can('manageRecurrence', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return app(ProcessTemplateRecurrenceService::class)->rules();
    }

    public function payload(): ProcessTemplateRecurrenceData
    {
        return new ProcessTemplateRecurrenceData($this->validated());
    }
}
