<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\MonitoringModuleMembershipFilters;
use App\Enums\FiscalModuleKey;
use Illuminate\Validation\Rule;

final class ListMonitoringModuleMembershipRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'module' => [
                'required',
                'string',
                Rule::in(FiscalModuleKey::values()),
            ],
            'submodule' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function filters(): MonitoringModuleMembershipFilters
    {
        $validated = $this->validated();
        $module = FiscalModuleKey::tryFromRoute((string) $validated['module'])
            ?? FiscalModuleKey::tryFrom((string) $validated['module']);

        return new MonitoringModuleMembershipFilters(
            module: $module === FiscalModuleKey::Dashboard ? null : $module,
            submodule: isset($validated['submodule'])
                ? (string) $validated['submodule']
                : null,
        );
    }
}
