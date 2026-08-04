<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Validation\ValidationException;

final class SaveSharedContactRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        $unexpected = array_diff(array_keys($this->all()), ['phone_index']);
        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(
            $unexpected,
            ['Este campo não é aceito; o contato é resolvido no servidor.'],
        ));
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }
        $inbox = $conversation->inbox()->first();

        return $inbox !== null
            && app(Access::class)->canView($actor, $inbox)
            && app(Access::class)->canManageContacts($actor, $inbox);
    }

    public function rules(): array
    {
        return [
            'phone_index' => ['required', 'integer', 'min:0', 'max:9'],
        ];
    }

    public function phoneIndex(): int
    {
        return (int) $this->validated('phone_index');
    }
}
