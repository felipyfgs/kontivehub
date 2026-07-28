<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCategoryUpdateData;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;

final readonly class UpdateClientCategoryAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(ClientCategory $category, ClientCategoryUpdateData $data): ClientCategory
    {
        $attributes = $data->attributes;
        $wasActive = (bool) $category->is_active;

        if (array_key_exists('name', $attributes)) {
            $category->name = $attributes['name'];
            $category->name_key = $attributes['_name_key'];
        }
        if (array_key_exists('color', $attributes)) {
            $category->color = $attributes['color'];
        }
        if (array_key_exists('is_active', $attributes)) {
            $category->is_active = (bool) $attributes['is_active'];
        }

        $changed = array_keys($category->getDirty());
        $category->save();

        $action = match (true) {
            $wasActive && ! $category->is_active => 'client_category.archive',
            ! $wasActive && $category->is_active => 'client_category.reactivate',
            default => 'client_category.update',
        };
        $this->audit->record($action, 'SUCCESS', $category, ['fields' => $changed]);

        return $category->loadCount('clients');
    }
}
