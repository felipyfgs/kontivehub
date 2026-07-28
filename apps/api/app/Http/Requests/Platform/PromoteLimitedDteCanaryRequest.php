<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\DteCanaryPromotionData;
use App\Http\Requests\AuthenticatedRequest;
use App\Support\Serpro\DteCanaryCoordinates;
use Carbon\CarbonImmutable;

final class PromoteLimitedDteCanaryRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirmation_phrase' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
            'change_window_start' => ['nullable', 'date'],
            'change_window_end' => ['nullable', 'date', 'after:change_window_start'],
            'max_quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function toDto(): DteCanaryPromotionData
    {
        $validated = $this->validated();

        return new DteCanaryPromotionData(
            confirmationPhrase: (string) $validated['confirmation_phrase'],
            reason: (string) $validated['reason'],
            changeWindowStart: isset($validated['change_window_start'])
                ? CarbonImmutable::parse((string) $validated['change_window_start'])
                : null,
            changeWindowEnd: isset($validated['change_window_end'])
                ? CarbonImmutable::parse((string) $validated['change_window_end'])
                : null,
            maxQuantity: (int) ($validated['max_quantity']
                ?? DteCanaryCoordinates::LIMITED_DEFAULT_MAX_QUANTITY),
        );
    }
}
