<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCategoryReplacementData;
use App\DTO\Clients\ClientCategoryReplacementResult;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReplaceClientCategoriesAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function __invoke(Client $client, ClientCategoryReplacementData $data): ClientCategoryReplacementResult
    {
        $tenantId = (int) $this->currentTenant->id();

        $result = DB::transaction(function () use ($client, $data, $tenantId): array {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $categories = $this->categoriesForIds($data->categoryIds);
            $existingIds = DB::table('client_category_assignments')
                ->where('tenant_id', $tenantId)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->pluck('client_category_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $toAdd = array_values(array_diff($data->categoryIds, $existingIds));
            $toRemove = array_values(array_diff($existingIds, $data->categoryIds));
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
                    ->where('tenant_id', $tenantId)
                    ->where('client_id', $client->id)
                    ->whereIn('client_category_id', $toRemove)
                    ->delete();
            }

            if ($toAdd !== []) {
                $now = now();
                DB::table('client_category_assignments')->insert(array_map(
                    static fn (int $categoryId): array => [
                        'tenant_id' => $tenantId,
                        'client_id' => $client->id,
                        'client_category_id' => $categoryId,
                        'assigned_by' => $data->actorId,
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

        $this->audit->record('client.categories.replace', 'SUCCESS', $client, [
            'category_ids' => $data->categoryIds,
            ...$result,
        ]);

        $client->load(['categories' => fn ($query) => $query->orderBy('name')->orderBy('id')]);

        return new ClientCategoryReplacementResult(
            client: $client,
            added: $result['added'],
            removed: $result['removed'],
        );
    }

    /** @param list<int> $categoryIds
     * @return Collection<int, ClientCategory>
     */
    private function categoriesForIds(array $categoryIds): Collection
    {
        if ($categoryIds === []) {
            return collect();
        }

        $categories = ClientCategory::query()
            ->whereKey($categoryIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($categories->count() !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'category_ids' => ['Uma ou mais categorias não pertencem ao escritório atual.'],
            ]);
        }

        return $categories;
    }
}
