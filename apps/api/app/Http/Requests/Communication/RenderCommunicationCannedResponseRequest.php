<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationCannedResponseRenderData;
use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class RenderCommunicationCannedResponseRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $canned = $this->route('canned');

        return $actor instanceof User
            && $canned instanceof CommunicationCannedResponse
            && app(CommunicationAccess::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function renderData(): CommunicationCannedResponseRenderData
    {
        return new CommunicationCannedResponseRenderData(
            conversationId: (int) $this->validated('conversation_id'),
        );
    }
}
