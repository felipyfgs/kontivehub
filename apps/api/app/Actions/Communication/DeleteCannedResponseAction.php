<?php

namespace App\Actions\Communication;

use App\Models\CommunicationCannedResponse;

final class DeleteCannedResponseAction
{
    public function handle(CommunicationCannedResponse $canned): void
    {
        $canned->delete();
    }
}
