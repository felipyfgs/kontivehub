<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcessTemplate;

final class ShowWorkProcessTemplateRecurrenceRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $template = $this->route('template');

        return $actor instanceof User
            && $template instanceof WorkProcessTemplate
            && $actor->can('view', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
