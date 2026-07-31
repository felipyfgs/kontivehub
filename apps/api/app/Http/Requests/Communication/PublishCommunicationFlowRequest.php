<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowPublicationData;

final class PublishCommunicationFlowRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function publicationData(): FlowPublicationData
    {
        return new FlowPublicationData(
            lockVersion: (int) $this->validated('lock_version'),
        );
    }
}
