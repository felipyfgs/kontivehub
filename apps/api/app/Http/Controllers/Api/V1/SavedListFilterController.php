<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedListFilter;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de presets de filtro de lista (tenant-scoped via CurrentTenant).
 * O tenant vem exclusivamente de CurrentTenant; tenant_id no payload é proibido.
 */
class SavedListFilterController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('viewAny', SavedListFilter::class);

        $data = $request->validate([
            'surface' => ['required', 'string', Rule::in(SavedListFilter::SURFACES)],
        ]);

        $tenantId = $currentTenant->tenant()->id;
        $userId = (int) $request->user()->id;
        $surface = $data['surface'];

        $items = SavedListFilter::query()
            ->with('user:id,name')
            ->where('tenant_id', $tenantId)
            ->where('surface', $surface)
            ->where(function ($q) use ($userId): void {
                $q->where(function ($personal) use ($userId): void {
                    $personal->where('visibility', SavedListFilter::VISIBILITY_PERSONAL)
                        ->where('user_id', $userId);
                })->orWhere('visibility', SavedListFilter::VISIBILITY_TENANT);
            })
            ->orderBy('visibility')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (SavedListFilter $f) => $this->public($f));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('create', SavedListFilter::class);

        $data = $this->validatePayload($request);
        $visibility = $data['visibility'] ?? SavedListFilter::VISIBILITY_PERSONAL;

        if ($visibility === SavedListFilter::VISIBILITY_TENANT) {
            $this->authorize('shareTenant', SavedListFilter::class);
        }

        $tenantId = $currentTenant->tenant()->id;
        $userId = (int) $request->user()->id;

        $this->assertUniqueName(
            tenantId: $tenantId,
            userId: $userId,
            surface: $data['surface'],
            name: $data['name'],
            visibility: $visibility,
        );

        $filter = SavedListFilter::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'surface' => $data['surface'],
            'name' => $data['name'],
            'visibility' => $visibility,
            'schema_version' => SavedListFilter::SCHEMA_VERSION,
            'payload' => $this->normalizePayload($data['payload'] ?? []),
        ]);

        return response()->json(['data' => $this->public($filter->load('user:id,name'))], 201);
    }

    public function update(
        Request $request,
        SavedListFilter $listFilter,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $this->authorize('update', $listFilter);

        // Reforço: model binding + BelongsToTenant já isolam, mas garante tenant atual.
        if ((int) $listFilter->tenant_id !== (int) $currentTenant->tenant()->id) {
            abort(404);
        }

        $data = $this->validatePayload($request, partial: true);

        $visibility = array_key_exists('visibility', $data)
            ? $data['visibility']
            : $listFilter->visibility;

        if ($visibility === SavedListFilter::VISIBILITY_TENANT) {
            // Autor precisa poder publicar; ADMIN já autorizado em update de tenant de terceiros.
            $isAuthor = (int) $listFilter->user_id === (int) $request->user()->id;
            if ($isAuthor || $listFilter->visibility !== SavedListFilter::VISIBILITY_TENANT) {
                $this->authorize('shareTenant', SavedListFilter::class);
            }
        }

        $name = array_key_exists('name', $data) ? $data['name'] : $listFilter->name;
        $surface = $listFilter->surface;

        if ($name !== $listFilter->name
            || $visibility !== $listFilter->visibility
        ) {
            $this->assertUniqueName(
                tenantId: (int) $listFilter->tenant_id,
                userId: (int) $listFilter->user_id,
                surface: $surface,
                name: $name,
                visibility: $visibility,
                exceptId: (int) $listFilter->id,
            );
        }

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }
        if (array_key_exists('visibility', $data)) {
            $updates['visibility'] = $data['visibility'];
        }
        if (array_key_exists('payload', $data)) {
            $updates['payload'] = $this->normalizePayload($data['payload'] ?? []);
        }

        if ($updates !== []) {
            $listFilter->fill($updates);
            $listFilter->save();
        }

        return response()->json(['data' => $this->public($listFilter->refresh()->load('user:id,name'))]);
    }

    public function destroy(
        SavedListFilter $listFilter,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $this->authorize('delete', $listFilter);

        if ((int) $listFilter->tenant_id !== (int) $currentTenant->tenant()->id) {
            abort(404);
        }

        $listFilter->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'surface' => $partial
                ? ['prohibited']
                : ['required', 'string', Rule::in(SavedListFilter::SURFACES)],
            'name' => [$required, 'string', 'max:120'],
            'visibility' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                Rule::in([SavedListFilter::VISIBILITY_PERSONAL, SavedListFilter::VISIBILITY_TENANT]),
            ],
            'tenant_id' => ['prohibited'],
            'schema_version' => ['prohibited'],
            'payload' => [$partial ? 'sometimes' : 'required', 'array'],
            'payload.schema_version' => ['prohibited'],
            'payload.tenant_id' => ['prohibited'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        return $payload;
    }

    private function assertUniqueName(
        int $tenantId,
        int $userId,
        string $surface,
        string $name,
        string $visibility,
        ?int $exceptId = null,
    ): void {
        $q = SavedListFilter::query()
            ->where('tenant_id', $tenantId)
            ->where('surface', $surface)
            ->where('name', $name)
            ->where('visibility', $visibility);

        if ($visibility === SavedListFilter::VISIBILITY_PERSONAL) {
            $q->where('user_id', $userId);
        }

        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Já existe um filtro salvo com este nome nesta superfície.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function public(SavedListFilter $filter): array
    {
        return [
            'id' => $filter->id,
            'surface' => $filter->surface,
            'name' => $filter->name,
            'visibility' => $filter->visibility,
            'schema_version' => $filter->schema_version,
            'payload' => $filter->payload ?? [],
            'author' => [
                'id' => $filter->user_id,
                'name' => $filter->user?->name,
            ],
            'permissions' => [
                'update' => Gate::allows('update', $filter),
                'delete' => Gate::allows('delete', $filter),
                'share' => Gate::allows('shareTenant', SavedListFilter::class),
            ],
            'created_at' => $filter->created_at?->toIso8601String(),
            'updated_at' => $filter->updated_at?->toIso8601String(),
            // tenant_id intencionalmente omitido do JSON público (contexto é a sessão).
        ];
    }
}
