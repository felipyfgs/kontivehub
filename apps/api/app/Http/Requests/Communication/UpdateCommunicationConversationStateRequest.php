<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use Illuminate\Validation\Rule;

final class UpdateCommunicationConversationStateRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['ARCHIVE', 'MUTE', 'PIN', 'STAR', 'MARK_READ', 'DELETE_CHAT'])],
            'value' => [
                'nullable',
                'boolean',
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('action'),
                    ['ARCHIVE', 'MUTE', 'PIN', 'STAR', 'MARK_READ'],
                    true,
                )),
            ],
            'message_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(fn (): bool => $this->input('action') === 'STAR'),
            ],
            'duration_seconds' => [
                'nullable',
                'integer',
                'min:0',
                'max:31536000',
                Rule::requiredIf(fn (): bool => $this->input('action') === 'MUTE'
                    && $this->boolean('value')),
            ],
            'delete_media' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation(array_filter([
            'action' => (string) $validated['action'],
            'value' => array_key_exists('value', $validated)
                ? (bool) $validated['value']
                : null,
            'message_id' => isset($validated['message_id'])
                ? (int) $validated['message_id']
                : null,
            'duration_seconds' => isset($validated['duration_seconds'])
                ? (int) $validated['duration_seconds']
                : null,
            'delete_media' => array_key_exists('delete_media', $validated)
                ? (bool) $validated['delete_media']
                : null,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
