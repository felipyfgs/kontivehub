<?php

namespace App\Http\Requests\Platform;

use App\DTO\Fiscal\FiscalModuleRestrictionData;
use App\Http\Requests\AuthenticatedRequest;

final class UpdateFiscalModuleRestrictionRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'restricted' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function toDto(): FiscalModuleRestrictionData
    {
        return new FiscalModuleRestrictionData(
            restricted: (bool) $this->validated('restricted'),
            reason: (string) $this->validated('reason'),
        );
    }
}
