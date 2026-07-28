<?php

namespace App\Actions\Platform;

use App\DTO\Fiscal\FiscalModuleRestrictionData;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Exceptions\FiscalModuleRestrictionException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Fiscal\Availability\FiscalModuleControlService;
use Illuminate\Http\Request;

final readonly class UpdateFiscalModuleRestrictionAction
{
    public function __construct(
        private FiscalModuleControlService $controls,
        private RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function __invoke(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?Tenant $tenant,
        FiscalModuleRestrictionData $data,
        User $actor,
        Request $request,
    ): void {
        $recent = $this->passwordGate->isRecentlyConfirmed($actor, $request);
        if (! $data->restricted && ! $recent) {
            throw FiscalModuleRestrictionException::passwordConfirmationRequired();
        }

        $this->controls->setRestriction(
            $module,
            $scope,
            $tenant,
            $data->restricted,
            $data->reason,
            $actor,
            $recent,
        );
    }
}
