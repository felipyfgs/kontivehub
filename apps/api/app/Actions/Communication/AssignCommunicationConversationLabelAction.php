<?php

namespace App\Actions\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Support\CurrentTenant;

final readonly class AssignCommunicationConversationLabelAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        CommunicationLabel $label,
    ): CommunicationLabel {
        $conversation->labels()->syncWithoutDetaching([$label->id => [
            'tenant_id' => $conversation->tenant_id,
            'assigned_by_membership_id' => $this->currentTenant->realMembership()?->id,
        ]]);

        return $label;
    }
}
