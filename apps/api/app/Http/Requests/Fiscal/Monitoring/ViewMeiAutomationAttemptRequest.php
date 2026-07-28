<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;
use App\Models\MeiAutomationAttempt;
use App\Services\Authorization\TenantAuthorization;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Support\CurrentTenant;

class ViewMeiAutomationAttemptRequest extends FiscalMonitoringViewRequest
{
    private ?MeiAutomationAttempt $resolvedAttempt = null;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return app(TenantAuthorization::class)->allows(
            $this->actor(),
            TenantPermission::FiscalMonitoringView,
            $this->attempt(),
        );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    final public function attempt(): MeiAutomationAttempt
    {
        if ($this->resolvedAttempt instanceof MeiAutomationAttempt) {
            return $this->resolvedAttempt;
        }

        $tenant = app(CurrentTenant::class)->tenant();

        return $this->resolvedAttempt = app(MeiAutomationAttemptRepository::class)
            ->findForTenant((int) $tenant->id, (int) $this->route('attempt'));
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Ação não autorizada.');
    }
}
