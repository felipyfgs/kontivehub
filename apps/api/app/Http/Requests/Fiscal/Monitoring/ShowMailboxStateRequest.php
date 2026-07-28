<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class ShowMailboxStateRequest extends MailboxReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'client_id obrigatório.',
        ];
    }

    public function clientId(): int
    {
        return (int) $this->validated('client_id');
    }
}
