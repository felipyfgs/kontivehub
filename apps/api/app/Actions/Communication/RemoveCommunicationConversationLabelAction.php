<?php

namespace App\Actions\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use Illuminate\Support\Facades\DB;

final readonly class RemoveCommunicationConversationLabelAction
{
    public function __construct(
        private CommunicationConversationCanonicalizer $canonicalizer,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        CommunicationLabel $label,
    ): void {
        DB::transaction(function () use ($conversation, $label): void {
            $this->canonicalizer
                ->lockConversation($conversation)
                ->labels()
                ->detach($label->id);
        });
    }
}
