<?php

namespace App\Actions\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class AssignCommunicationConversationLabelAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationConversationCanonicalizer $canonicalizer,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        CommunicationLabel $label,
    ): CommunicationLabel {
        DB::transaction(function () use ($conversation, $label): void {
            $canonical = $this->canonicalizer->lockConversation($conversation);
            $canonical->labels()->syncWithoutDetaching([$label->id => [
                'tenant_id' => $canonical->tenant_id,
                'assigned_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]]);
        });

        return $label;
    }
}
