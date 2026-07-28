<?php

namespace App\Http\Resources;

use App\Models\ClientCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCategory */
final class ClientCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCategory $category */
        $category = $this->resource;

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'color' => (string) $category->color,
            'is_active' => (bool) $category->is_active,
            'clients_count' => $this->whenHas(
                'clients_count',
                fn (): int => (int) $category->clients_count,
            ),
        ];
    }
}
