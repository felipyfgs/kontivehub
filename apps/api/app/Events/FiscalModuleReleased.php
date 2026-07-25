<?php

namespace App\Events;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FiscalModuleReleased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FiscalControlModule $module,
        public FiscalModuleControlScope $scope,
        public ?int $officeId,
        public int $actorUserId,
    ) {}
}
