<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\DteCanaryDisableData;
use App\Http\Requests\AuthenticatedRequest;

final class DisableDteCanaryRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirmation_phrase' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function toDto(): DteCanaryDisableData
    {
        return new DteCanaryDisableData(
            confirmationPhrase: (string) $this->validated('confirmation_phrase'),
            reason: (string) $this->validated('reason'),
        );
    }
}
