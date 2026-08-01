<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\CommentData;
use App\Models\User;
use App\Models\WorkProcess;

final class CommentProcessRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $process = $this->route('process');

        return $actor instanceof User
            && $process instanceof WorkProcess
            && $actor->can('comment', $process);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    public function comment(): CommentData
    {
        return new CommentData((string) $this->validated('body'));
    }
}
