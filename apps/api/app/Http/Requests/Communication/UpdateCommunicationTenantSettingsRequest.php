<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\TenantSettingsData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class UpdateCommunicationTenantSettingsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function settingsData(): TenantSettingsData
    {
        return new TenantSettingsData(
            enabled: (bool) $this->validated('enabled'),
        );
    }
}
