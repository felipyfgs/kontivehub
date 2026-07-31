<?php

namespace App\Actions\Communication;

use App\DTO\Communication\LabelCreationData;
use App\Models\CommunicationLabel;
use App\Support\CurrentTenant;

final readonly class CreateLabelAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(LabelCreationData $data): CommunicationLabel
    {
        return CommunicationLabel::query()->create([
            'tenant_id' => $this->currentTenant->tenant()->id,
            'name' => $data->name,
            'color' => $data->color,
        ]);
    }
}
