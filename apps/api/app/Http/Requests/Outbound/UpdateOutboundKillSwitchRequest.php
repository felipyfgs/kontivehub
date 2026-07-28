<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundKillSwitchData;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class UpdateOutboundKillSwitchRequest extends AdministerOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'profile_id' => [
                Rule::requiredIf(
                    fn (): bool => ! app(CurrentTenant::class)
                        ->isPlatformPrivileged(),
                ),
                'integer',
                'min:1',
                Rule::exists('outbound_capture_profiles', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
        ];
    }

    public function killSwitchData(): OutboundKillSwitchData
    {
        $validated = $this->validated();

        return new OutboundKillSwitchData(
            active: (bool) $validated['active'],
            reason: (string) $validated['reason'],
            profileId: isset($validated['profile_id'])
                ? (int) $validated['profile_id']
                : null,
        );
    }
}
