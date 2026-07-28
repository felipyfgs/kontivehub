<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class UpdateCommunicationPreferencesRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'automatic_requested' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array{email_enabled: bool, whatsapp_enabled: bool, automatic_requested: bool, lock_version: int} */
    public function preferences(): array
    {
        $data = $this->validated();

        return [
            'email_enabled' => (bool) $data['email_enabled'],
            'whatsapp_enabled' => (bool) $data['whatsapp_enabled'],
            'automatic_requested' => (bool) $data['automatic_requested'],
            'lock_version' => (int) $data['lock_version'],
        ];
    }
}
