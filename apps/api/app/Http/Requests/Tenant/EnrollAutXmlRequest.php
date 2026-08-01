<?php

namespace App\Http\Requests\Tenant;

final class EnrollAutXmlRequest extends AutXmlRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'establishment_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function establishmentId(): int
    {
        return $this->integer('establishment_id');
    }

    protected function requiresManagementPermission(): bool
    {
        return true;
    }
}
