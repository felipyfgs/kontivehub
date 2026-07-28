<?php

namespace App\Actions\Clients;

use App\DTO\Clients\BulkClientCategoryUpdateData;
use App\DTO\Clients\BulkClientCategoryUpdateResult;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BulkUpdateClientCategoriesAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
        private Gate $gate,
    ) {}

    public function __invoke(BulkClientCategoryUpdateData $data): BulkClientCategoryUpdateResult
    {
        $tenantId = (int) $this->currentTenant->id();

        /** @var Collection<int, Client> $clients */
        $clients = collect();
        $result = DB::transaction(function () use ($data, $tenantId, &$clients): array {
            $clients = Client::query()
                ->whereKey($data->clientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($clients->count() !== count($data->clientIds)) {
                throw ValidationException::withMessages([
                    'client_ids' => ['Um ou mais clientes não pertencem ao escritório atual ou não estão disponíveis.'],
                ]);
            }

            foreach ($clients as $client) {
                $this->gate->forUser($data->actor)->authorize('update', $client);
            }

            $categories = $this->categoriesForIds($data->categoryIds);
            if ($data->operation === 'add'
                && $categories->contains(fn (ClientCategory $category) => ! $category->is_active)) {
                throw ValidationException::withMessages([
                    'category_ids' => ['Categorias arquivadas não podem receber novas atribuições.'],
                ]);
            }

            if ($data->operation === 'remove') {
                $removed = DB::table('client_category_assignments')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('client_id', $data->clientIds)
                    ->whereIn('client_category_id', $data->categoryIds)
                    ->delete();

                return ['created_links' => 0, 'removed_links' => $removed];
            }

            $now = now();
            $rows = [];
            foreach ($data->clientIds as $clientId) {
                foreach ($data->categoryIds as $categoryId) {
                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'client_id' => $clientId,
                        'client_category_id' => $categoryId,
                        'assigned_by' => $data->actor->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            return [
                'created_links' => DB::table('client_category_assignments')->insertOrIgnore($rows),
                'removed_links' => 0,
            ];
        });

        foreach ($clients->values() as $client) {
            $this->audit->record('client.categories.bulk_'.$data->operation, 'SUCCESS', $client, [
                'category_ids' => $data->categoryIds,
                'batch_size' => count($data->clientIds),
            ]);
        }

        return new BulkClientCategoryUpdateResult(
            operation: $data->operation,
            clientIds: $data->clientIds,
            categoryIds: $data->categoryIds,
            createdLinks: $result['created_links'],
            removedLinks: $result['removed_links'],
        );
    }

    /**
     * @param  list<int>  $categoryIds
     * @return Collection<int, ClientCategory>
     */
    private function categoriesForIds(array $categoryIds): Collection
    {
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
