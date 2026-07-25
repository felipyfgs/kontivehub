<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\BulkUpdateClientCategoriesRequest;
use App\Http\Requests\Clients\ReplaceClientCategoriesRequest;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientCategoryAssignmentController extends Controller
{
    public function replace(
        ReplaceClientCategoriesRequest $request,
        Client $client,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $client);
        $this->assertRootClient($client);

        $categoryIds = array_values(array_map('intval', $request->validated('category_ids')));
        $actorId = $request->user()?->id;
        $officeId = (int) $currentOffice->id();

        $result = DB::transaction(function () use ($client, $categoryIds, $actorId, $officeId): array {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $categories = $this->categoriesForIds($categoryIds, true);
            $existingIds = DB::table('client_category_assignments')
                ->where('office_id', $officeId)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->pluck('client_category_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $toAdd = array_values(array_diff($categoryIds, $existingIds));
            $toRemove = array_values(array_diff($existingIds, $categoryIds));
            $inactiveAdditions = $categories
                ->whereIn('id', $toAdd)
                ->where('is_active', false)
                ->pluck('id')
                ->all();

            if ($inactiveAdditions !== []) {
                throw ValidationException::withMessages([
                    'category_ids' => ['Categorias arquivadas não podem receber novas atribuições.'],
                ]);
            }

            if ($toRemove !== []) {
                DB::table('client_category_assignments')
                    ->where('office_id', $officeId)
                    ->where('client_id', $client->id)
                    ->whereIn('client_category_id', $toRemove)
                    ->delete();
            }

            $now = now();
            if ($toAdd !== []) {
                DB::table('client_category_assignments')->insert(array_map(
                    static fn (int $categoryId): array => [
                        'office_id' => $officeId,
                        'client_id' => $client->id,
                        'client_category_id' => $categoryId,
                        'assigned_by' => $actorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $toAdd,
                ));
            }

            return [
                'added' => count($toAdd),
                'removed' => count($toRemove),
            ];
        });

        $audit->record('client.categories.replace', 'SUCCESS', $client, [
            'category_ids' => $categoryIds,
            ...$result,
        ]);

        $client->load(['categories' => fn ($query) => $query->orderBy('name')->orderBy('id')]);

        return response()->json([
            'data' => [
                'client_id' => (int) $client->id,
                'categories' => $this->serializeCategories($client->categories),
                ...$result,
            ],
        ]);
    }

    public function bulk(
        BulkUpdateClientCategoriesRequest $request,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validated();
        $operation = (string) $data['operation'];
        $clientIds = array_values(array_map('intval', $data['client_ids']));
        $categoryIds = array_values(array_map('intval', $data['category_ids']));
        $actorId = $request->user()?->id;
        $officeId = (int) $currentOffice->id();

        /** @var Collection<int, Client> $clients */
        $clients = collect();
        $result = DB::transaction(function () use (
            $operation,
            $clientIds,
            $categoryIds,
            $actorId,
            $officeId,
            &$clients,
        ): array {
            $clients = Client::query()
                ->whereNull('matrix_client_id')
                ->whereKey($clientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($clients->count() !== count($clientIds)) {
                throw ValidationException::withMessages([
                    'client_ids' => ['Um ou mais clientes não pertencem ao escritório atual ou não estão disponíveis.'],
                ]);
            }

            foreach ($clients as $client) {
                $this->authorize('update', $client);
            }

            $categories = $this->categoriesForIds($categoryIds, true);
            if ($operation === 'add' && $categories->contains(fn (ClientCategory $category) => ! $category->is_active)) {
                throw ValidationException::withMessages([
                    'category_ids' => ['Categorias arquivadas não podem receber novas atribuições.'],
                ]);
            }

            if ($operation === 'remove') {
                $removed = DB::table('client_category_assignments')
                    ->where('office_id', $officeId)
                    ->whereIn('client_id', $clientIds)
                    ->whereIn('client_category_id', $categoryIds)
                    ->delete();

                return ['created_links' => 0, 'removed_links' => $removed];
            }

            $now = now();
            $rows = [];
            foreach ($clientIds as $clientId) {
                foreach ($categoryIds as $categoryId) {
                    $rows[] = [
                        'office_id' => $officeId,
                        'client_id' => $clientId,
                        'client_category_id' => $categoryId,
                        'assigned_by' => $actorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $created = DB::table('client_category_assignments')->insertOrIgnore($rows);

            return ['created_links' => $created, 'removed_links' => 0];
        });

        foreach ($clients->values() as $client) {
            $audit->record('client.categories.bulk_'.$operation, 'SUCCESS', $client, [
                'category_ids' => $categoryIds,
                'batch_size' => count($clientIds),
            ]);
        }

        return response()->json([
            'data' => [
                'operation' => $operation,
                'updated_clients' => count($clientIds),
                'client_ids' => $clientIds,
                'category_ids' => $categoryIds,
                ...$result,
            ],
        ]);
    }

    /** @return Collection<int, ClientCategory> */
    private function categoriesForIds(array $categoryIds, bool $lock): Collection
    {
        if ($categoryIds === []) {
            return collect();
        }

        $query = ClientCategory::query()->whereKey($categoryIds);
        if ($lock) {
            $query->lockForUpdate();
        }
        $categories = $query->get()->keyBy('id');

        if ($categories->count() !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'category_ids' => ['Uma ou mais categorias não pertencem ao escritório atual.'],
            ]);
        }

        return $categories;
    }

    private function assertRootClient(Client $client): void
    {
        if ($client->matrix_client_id !== null) {
            throw ValidationException::withMessages([
                'client' => ['Categorias só podem ser atribuídas ao cliente-raiz canônico.'],
            ]);
        }
    }

    /** @return list<array{id: int, name: string, color: string, is_active: bool}> */
    private function serializeCategories(Collection $categories): array
    {
        return $categories->map(static fn (ClientCategory $category): array => [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'color' => (string) $category->color,
            'is_active' => (bool) $category->is_active,
        ])->values()->all();
    }
}
