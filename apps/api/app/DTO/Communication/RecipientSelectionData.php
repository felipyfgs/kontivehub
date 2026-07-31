<?php

namespace App\DTO\Communication;

use App\Enums\Communication\RecipientMode;

final readonly class RecipientSelectionData
{
    /**
     * @param  list<int>  $identityIds
     */
    public function __construct(
        public AutomationScopeData $scope,
        public RecipientMode $recipientMode,
        public array $identityIds,
        public int $lockVersion,
    ) {}
}
