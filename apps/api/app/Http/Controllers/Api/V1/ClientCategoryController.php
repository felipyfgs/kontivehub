<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientCategoryRequest;
use App\Http\Requests\Clients\UpdateClientCategoryRequest;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClientCategory::class);

        $query = ClientCategory::query()->withCount('clients');
        if (! $request->boolean('include_archived')) {
            $query->where('is_active', true);
        }

        $categories = $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (ClientCategory $category): array => $this->serialize($category));

        return response()->json(['data' => $categories]);
    }

    public function store(
        StoreClientCategoryRequest $request,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('create', ClientCategory::class);
        $data = $request->validated();

        $category = ClientCategory::query()->create([
            'office_id' => $currentOffice->id(),
            'name' => $data['name'],
            'name_key' => $data['_name_key'],
            'color' => $data['color'],
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        $audit->record('client_category.create', 'SUCCESS', $category, [
            'color' => $category->color,
        ]);

        $category->loadCount('clients');

        return response()->json(['data' => $this->serialize($category)], 201);
    }

    public function update(
        UpdateClientCategoryRequest $request,
        ClientCategory $clientCategory,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $clientCategory);
        $data = $request->validated();
        $wasActive = (bool) $clientCategory->is_active;

        if (array_key_exists('name', $data)) {
            $clientCategory->name = $data['name'];
            $clientCategory->name_key = $data['_name_key'];
        }
        if (array_key_exists('color', $data)) {
            $clientCategory->color = $data['color'];
        }
        if (array_key_exists('is_active', $data)) {
            $clientCategory->is_active = (bool) $data['is_active'];
        }

        $changed = array_keys($clientCategory->getDirty());
        $clientCategory->save();

        $action = match (true) {
            $wasActive && ! $clientCategory->is_active => 'client_category.archive',
            ! $wasActive && $clientCategory->is_active => 'client_category.reactivate',
            default => 'client_category.update',
        };
        $audit->record($action, 'SUCCESS', $clientCategory, ['fields' => $changed]);

        $clientCategory->loadCount('clients');

        return response()->json(['data' => $this->serialize($clientCategory)]);
    }

    /** @return array{id: int, name: string, color: string, is_active: bool, clients_count: int} */
    private function serialize(ClientCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'color' => (string) $category->color,
            'is_active' => (bool) $category->is_active,
            'clients_count' => (int) ($category->clients_count ?? 0),
        ];
    }
}
