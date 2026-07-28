<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationLabelCreationData;
use App\Models\CommunicationLabel;
use App\Support\CurrentTenant;

final readonly class CreateCommunicationLabelAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(CommunicationLabelCreationData $data): CommunicationLabel
    {
        return CommunicationLabel::query()->create([
            'tenant_id' => $this->currentTenant->tenant()->id,
            'name' => $data->name,
            'color' => $data->color,
        ]);
    }
}
