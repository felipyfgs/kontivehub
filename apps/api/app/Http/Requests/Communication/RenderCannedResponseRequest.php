<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CannedResponseRenderData;
use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class RenderCannedResponseRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $canned = $this->route('canned');

        return $actor instanceof User
            && $canned instanceof CommunicationCannedResponse
            && app(Access::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function renderData(): CannedResponseRenderData
    {
        return new CannedResponseRenderData(
            conversationId: (int) $this->validated('conversation_id'),
        );
    }
}
