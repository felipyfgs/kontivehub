<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationTenantSettingsData;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class UpdateCommunicationTenantSettingsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function settingsData(): CommunicationTenantSettingsData
    {
        return new CommunicationTenantSettingsData(
            enabled: (bool) $this->validated('enabled'),
        );
    }
}
