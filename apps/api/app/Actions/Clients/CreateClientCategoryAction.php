<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCategoryCreationData;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;

final readonly class CreateClientCategoryAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function __invoke(ClientCategoryCreationData $data): ClientCategory
    {
        $category = ClientCategory::query()->create([
            'tenant_id' => $this->currentTenant->id(),
            'name' => $data->name,
            'name_key' => $data->nameKey,
            'color' => $data->color,
            'is_active' => true,
            'created_by' => $data->actorId,
        ]);

        $this->audit->record('client_category.create', 'SUCCESS', $category, [
            'color' => $category->color,
        ]);

        return $category->loadCount('clients');
    }
}
