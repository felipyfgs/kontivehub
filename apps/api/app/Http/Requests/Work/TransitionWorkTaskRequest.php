<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\TaskTransitionData;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Validation\Rule;

final class TransitionWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('transition', $task);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => [
                Rule::requiredIf($this->route()?->getActionMethod() === 'block'),
                'string',
                'max:2000',
            ],
        ];
    }

    public function transition(): TaskTransitionData
    {
        $validated = $this->validated();

        return new TaskTransitionData(
            lockVersion: (int) $validated['lock_version'],
            reason: isset($validated['reason'])
                ? (string) $validated['reason']
                : null,
        );
    }
}
