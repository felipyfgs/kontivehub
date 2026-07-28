<?php

namespace App\Actions\Communication;

use App\Models\CommunicationCannedResponse;

final class DeleteCommunicationCannedResponseAction
{
    public function handle(CommunicationCannedResponse $canned): void
    {
        $canned->delete();
    }
}
