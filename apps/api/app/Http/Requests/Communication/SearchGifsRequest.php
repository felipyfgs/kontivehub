<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class SearchGifsRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = CommunicationInbox::query()->find($this->integer('inbox_id'));

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(Access::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'inbox_id' => ['required', 'integer', 'min:1'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function inbox(): CommunicationInbox
    {
        return CommunicationInbox::query()->findOrFail((int) $this->validated('inbox_id'));
    }
}
