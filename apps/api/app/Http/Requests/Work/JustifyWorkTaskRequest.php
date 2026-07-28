<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkTaskTransitionData;
use App\Models\User;
use App\Models\WorkTask;

final class JustifyWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');
        $ability = $this->route()?->getActionMethod() === 'reopen'
            ? 'reopen'
            : 'dispense';

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can($ability, $task);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'justification' => ['required', 'string', 'max:2000'],
        ];
    }

    public function transition(): WorkTaskTransitionData
    {
        return new WorkTaskTransitionData(
            lockVersion: $this->integer('lock_version'),
            justification: (string) $this->validated('justification'),
        );
    }
}
