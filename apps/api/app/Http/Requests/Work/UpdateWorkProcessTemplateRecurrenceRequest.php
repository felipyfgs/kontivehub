<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkProcessTemplateRecurrenceData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessTemplateRecurrenceService;

final class UpdateWorkProcessTemplateRecurrenceRequest extends WorkRequest
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
        return app(WorkProcessTemplateRecurrenceService::class)->rules();
    }

    public function payload(): WorkProcessTemplateRecurrenceData
    {
        return new WorkProcessTemplateRecurrenceData($this->validated());
    }
}
