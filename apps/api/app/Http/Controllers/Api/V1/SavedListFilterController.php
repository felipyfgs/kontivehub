<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedListFilter;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de presets de filtro de lista (tenant-scoped via CurrentOffice).
 * Nunca confia office_id do client (já stripado por EnsureOfficeContext; reforço local).
 */
class SavedListFilterController extends Controller
{
    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('viewAny', SavedListFilter::class);

        $data = $request->validate([
            'surface' => ['required', 'string', 'max:128'],
        ]);

        $officeId = $currentOffice->office()->id;
        $userId = (int) $request->user()->id;
        $surface = $data['surface'];

        $items = SavedListFilter::query()
            ->where('office_id', $officeId)
            ->where('surface', $surface)
            ->where(function ($q) use ($userId): void {
                $q->where(function ($personal) use ($userId): void {
                    $personal->where('visibility', SavedListFilter::VISIBILITY_PERSONAL)
                        ->where('user_id', $userId);
                })->orWhere('visibility', SavedListFilter::VISIBILITY_OFFICE);
            })
            ->orderBy('visibility')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (SavedListFilter $f) => $this->public($f));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('create', SavedListFilter::class);
        $this->stripClientOfficeId($request);

        $data = $this->validatePayload($request);
        $visibility = $data['visibility'] ?? SavedListFilter::VISIBILITY_PERSONAL;

        if ($visibility === SavedListFilter::VISIBILITY_OFFICE) {
            $this->authorize('shareOffice', SavedListFilter::class);
        }

        $officeId = $currentOffice->office()->id;
        $userId = (int) $request->user()->id;

        $this->assertUniqueName(
            officeId: $officeId,
            userId: $userId,
            surface: $data['surface'],
            name: $data['name'],
            visibility: $visibility,
        );

        $filter = SavedListFilter::query()->create([
            'office_id' => $officeId,
            'user_id' => $userId,
            'surface' => $data['surface'],
            'name' => $data['name'],
            'visibility' => $visibility,
            'schema_version' => $data['schema_version'] ?? 1,
            'payload' => $this->normalizePayload($data['payload'] ?? []),
        ]);

        return response()->json(['data' => $this->public($filter)], 201);
    }

    public function update(
        Request $request,
        SavedListFilter $listFilter,
        CurrentOffice $currentOffice,
    ): JsonResponse {
        $this->authorize('update', $listFilter);
        $this->stripClientOfficeId($request);

        // Reforço: model binding + BelongsToOffice já isolam, mas garante office atual.
        if ((int) $listFilter->office_id !== (int) $currentOffice->office()->id) {
            abort(404);
        }

        $data = $this->validatePayload($request, partial: true);

        $visibility = array_key_exists('visibility', $data)
            ? $data['visibility']
            : $listFilter->visibility;

        if ($visibility === SavedListFilter::VISIBILITY_OFFICE) {
            // Autor precisa poder publicar; ADMIN já autorizado em update de office de terceiros.
            $isAuthor = (int) $listFilter->user_id === (int) $request->user()->id;
            if ($isAuthor || $listFilter->visibility !== SavedListFilter::VISIBILITY_OFFICE) {
                $this->authorize('shareOffice', SavedListFilter::class);
            }
        }

        $name = array_key_exists('name', $data) ? $data['name'] : $listFilter->name;
        $surface = array_key_exists('surface', $data) ? $data['surface'] : $listFilter->surface;

        if ($name !== $listFilter->name
            || $visibility !== $listFilter->visibility
            || $surface !== $listFilter->surface
        ) {
            $this->assertUniqueName(
                officeId: (int) $listFilter->office_id,
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
        if (array_key_exists('surface', $data)) {
            $updates['surface'] = $data['surface'];
        }
        if (array_key_exists('visibility', $data)) {
            $updates['visibility'] = $data['visibility'];
        }
        if (array_key_exists('schema_version', $data)) {
            $updates['schema_version'] = $data['schema_version'];
        }
        if (array_key_exists('payload', $data)) {
            $updates['payload'] = $this->normalizePayload($data['payload'] ?? []);
        }

        if ($updates !== []) {
            $listFilter->fill($updates);
            $listFilter->save();
        }

        return response()->json(['data' => $this->public($listFilter->refresh())]);
    }

    public function destroy(
        SavedListFilter $listFilter,
        CurrentOffice $currentOffice,
    ): JsonResponse {
        $this->authorize('delete', $listFilter);

        if ((int) $listFilter->office_id !== (int) $currentOffice->office()->id) {
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
            'surface' => [$required, 'string', 'max:128'],
            'name' => [$required, 'string', 'max:120'],
            'visibility' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                Rule::in([SavedListFilter::VISIBILITY_PERSONAL, SavedListFilter::VISIBILITY_OFFICE]),
            ],
            'schema_version' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'payload' => [$partial ? 'sometimes' : 'nullable', 'array'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        unset($payload['office_id']);

        return $payload;
    }

    private function assertUniqueName(
        int $officeId,
        int $userId,
        string $surface,
        string $name,
        string $visibility,
        ?int $exceptId = null,
    ): void {
        $q = SavedListFilter::query()
            ->where('office_id', $officeId)
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

    private function stripClientOfficeId(Request $request): void
    {
        $request->request->remove('office_id');
        $request->query->remove('office_id');
        if ($request->isJson() && $request->json() !== null) {
            $request->json()->remove('office_id');
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
            'user_id' => $filter->user_id,
            'created_at' => $filter->created_at?->toIso8601String(),
            'updated_at' => $filter->updated_at?->toIso8601String(),
            // office_id intencionalmente omitido do JSON público (contexto é a sessão).
        ];
    }
}
