<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkCommentData;
use App\Models\User;
use App\Models\WorkTask;

final class CommentWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('comment', $task);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    public function comment(): WorkCommentData
    {
        return new WorkCommentData((string) $this->validated('body'));
    }
}
