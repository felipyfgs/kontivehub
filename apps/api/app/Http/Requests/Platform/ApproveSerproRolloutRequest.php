<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproRolloutApprovalData;
use App\Http\Requests\AuthenticatedRequest;
use Carbon\CarbonImmutable;

final class ApproveSerproRolloutRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'confirmation_phrase' => ['sometimes', 'nullable', 'string', 'max:120'],
            'change_window_start' => ['sometimes', 'nullable', 'date'],
            'change_window_end' => ['sometimes', 'nullable', 'date', 'after:change_window_start'],
        ];
    }

    public function toDto(): SerproRolloutApprovalData
    {
        $data = $this->validated();

        return new SerproRolloutApprovalData(
            reason: $data['reason'] ?? null,
            confirmationPhrase: $data['confirmation_phrase'] ?? null,
            changeWindowStart: isset($data['change_window_start'])
                ? CarbonImmutable::parse($data['change_window_start'])
                : null,
            changeWindowEnd: isset($data['change_window_end'])
                ? CarbonImmutable::parse($data['change_window_end'])
                : null,
        );
    }
}
