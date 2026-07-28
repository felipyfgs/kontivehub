<?php

namespace App\DTO\Communication;

use App\Enums\Communication\RecipientMode;

final readonly class CommunicationRecipientSelectionData
{
    /**
     * @param  list<int>  $identityIds
     */
    public function __construct(
        public CommunicationAutomationScopeData $scope,
        public RecipientMode $recipientMode,
        public array $identityIds,
        public int $lockVersion,
    ) {}
}
