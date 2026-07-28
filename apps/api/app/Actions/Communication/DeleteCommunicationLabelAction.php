<?php

namespace App\Actions\Communication;

use App\Models\CommunicationLabel;

final class DeleteCommunicationLabelAction
{
    public function handle(CommunicationLabel $label): void
    {
        $label->delete();
    }
}
