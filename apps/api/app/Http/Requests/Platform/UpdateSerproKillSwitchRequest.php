<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproKillSwitchData;
use App\Http\Requests\AuthenticatedRequest;
use Carbon\CarbonImmutable;

final class UpdateSerproKillSwitchRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
            'solution' => ['sometimes', 'nullable', 'string', 'max:80'],
            'confirmation_phrase' => ['required_if:active,false', 'nullable', 'string', 'max:120'],
            'change_window_start' => ['required_if:active,false', 'nullable', 'date'],
            'change_window_end' => ['required_if:active,false', 'nullable', 'date', 'after:change_window_start'],
        ];
    }

    public function toDto(): SerproKillSwitchData
    {
        $validated = $this->validated();
        $solution = isset($validated['solution'])
            ? strtoupper(trim((string) $validated['solution']))
            : null;

        return new SerproKillSwitchData(
            active: (bool) $validated['active'],
            reason: (string) $validated['reason'],
            solution: $solution === '' ? null : $solution,
            confirmationPhrase: isset($validated['confirmation_phrase'])
                ? (string) $validated['confirmation_phrase']
                : null,
            changeWindowStart: isset($validated['change_window_start'])
                ? CarbonImmutable::parse((string) $validated['change_window_start'])
                : null,
            changeWindowEnd: isset($validated['change_window_end'])
                ? CarbonImmutable::parse((string) $validated['change_window_end'])
                : null,
        );
    }
}
