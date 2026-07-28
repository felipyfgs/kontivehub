<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationRecipientSelectionData;
use App\Enums\Communication\RecipientMode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCommunicationAutomationRecipientsRequest extends CommunicationAutomationRecipientsRequest
{
    /** @return array<string, list<mixed>> */
    protected function recipientRules(): array
    {
        return [
            'recipient_mode' => ['required', Rule::enum(RecipientMode::class)],
            'identity_ids' => ['present', 'array', 'max:50'],
            'identity_ids.*' => ['integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return list<callable(Validator): void> */
    protected function recipientAfterValidation(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('recipient_mode') === RecipientMode::Selected->value
                    && $this->input('identity_ids') === []) {
                    $validator->errors()->add(
                        'identity_ids',
                        'Modo SELECTED exige ao menos um destinatário.',
                    );
                }
            },
        ];
    }

    public function selection(): CommunicationRecipientSelectionData
    {
        $validated = $this->validated();
        $identityIds = array_values(array_unique(array_map(
            static fn ($identityId): int => (int) $identityId,
            $validated['identity_ids'],
        )));

        return new CommunicationRecipientSelectionData(
            scope: $this->scope(),
            recipientMode: RecipientMode::from($validated['recipient_mode']),
            identityIds: $identityIds,
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
