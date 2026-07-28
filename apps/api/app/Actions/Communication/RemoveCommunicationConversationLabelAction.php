<?php

namespace App\Actions\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;

final class RemoveCommunicationConversationLabelAction
{
    public function handle(
        CommunicationConversation $conversation,
        CommunicationLabel $label,
    ): void {
        $conversation->labels()->detach($label->id);
    }
}
