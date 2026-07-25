<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;
use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Services\FiscalMonitoring\FiscalCategoryService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Catálogo de categorias e vínculos tenant-scoped (associação em lote).
 */
class FiscalCategoryController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly FiscalCategoryService $categories,
    ) {}

    public function indexCategories(): JsonResponse
    {
        $this->assertCanRead();
        $items = $this->categories->listCategories(true);

        return response()->json([
            'data' => $items->map(fn (FiscalCategory $c) => $c->toPublicArray())->values(),
        ]);
    }

    public function indexLinks(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $clientId = $request->query('client_id');
        $status = $request->query('status');

        $links = $this->categories->listLinks(
            $office,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($status) ? $status : null,
        );

        return response()->json([
            'data' => $links->map(fn ($l) => $l->toPublicArray())->values(),
        ]);
    }

    public function associate(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'fiscal_category_id' => ['required', 'integer', 'exists:fiscal_categories,id'],
            'coverage' => ['sometimes', 'string', Rule::enum(FiscalCoverage::class)],
            'status' => ['sometimes', 'string', Rule::enum(FiscalLinkStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $category = FiscalCategory::query()->findOrFail($data['fiscal_category_id']);

        try {
            $link = $this->categories->associate(
                $office,
                $client,
                $category,
                $request->user()?->id,
                isset($data['coverage']) ? FiscalCoverage::from($data['coverage']) : null,
                isset($data['status']) ? FiscalLinkStatus::from($data['status']) : FiscalLinkStatus::Active,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $link->toPublicArray()], 201);
    }

    public function associateBatch(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'fiscal_category_id' => ['required', 'integer', 'exists:fiscal_categories,id'],
            'client_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_ids.*' => ['integer'],
            'coverage' => ['sometimes', 'string', Rule::enum(FiscalCoverage::class)],
        ]);

        $category = FiscalCategory::query()->findOrFail($data['fiscal_category_id']);

        $result = $this->categories->associateBatch(
            $office,
            $category,
            $data['client_ids'],
            $request->user()?->id,
            isset($data['coverage']) ? FiscalCoverage::from($data['coverage']) : null,
        );

        return response()->json(['data' => $result]);
    }

    private function assertCanRead(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
