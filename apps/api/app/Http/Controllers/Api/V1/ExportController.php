<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FiscalModuleKey;
use App\Enums\FiscalSituation;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Jobs\BuildExportZipJob;
use App\Models\Export;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __construct(private readonly TenantAuthorization $authorization) {}

    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $sort = match ($request->string('sort')->toString()) {
            'status' => 'status',
            'created_at' => 'created_at',
            'files_count' => 'files_count',
            default => 'id',
        };
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $query = Export::query()
            ->where('office_id', $currentOffice->id())
            ->where('user_id', auth()->id())
            ->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Export $e) => $this->public($e)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::ExportsCreate)) {
            abort(403);
        }

        // office_id nunca do client (top-level já stripado; reforço em filters).
        $request->request->remove('office_id');
        $request->query->remove('office_id');
        if ($request->isJson() && $request->json() !== null) {
            $request->json()->remove('office_id');
        }

        $data = $request->validate([
            'filters' => ['nullable', 'array'],
            'filters.export_scope' => ['sometimes', 'nullable', 'string', Rule::in(['documents', 'fiscal_portfolio'])],
            'filters.competence' => ['sometimes', 'nullable', 'string', 'max:7'],
            'filters.access_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.access_keys' => ['sometimes', 'nullable', 'array', 'max:'.BuildExportZipJob::MAX_ACCESS_KEYS],
            'filters.access_keys.*' => ['string', 'max:64'],
            'filters.issuer_cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.taker_cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.fiscal_role' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.direction' => ['sometimes', 'nullable', 'in:IN,OUT,UNKNOWN'],
            'filters.status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.issued_from' => ['sometimes', 'nullable', 'date'],
            'filters.issued_to' => ['sometimes', 'nullable', 'date'],
            'filters.client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // fiscal_portfolio
            'filters.module_key' => ['required_if:filters.export_scope,fiscal_portfolio', 'nullable', 'string', 'max:64'],
            'filters.situation' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters.submodule' => ['sometimes', 'nullable', 'string', 'max:64'],
            'include_events' => ['sometimes', 'boolean'],
        ]);

        $rawFilters = $data['filters'] ?? [];
        // Nunca persistir office_id fornecido pelo cliente.
        unset($rawFilters['office_id']);

        $filters = $this->normalizeFilters($rawFilters);
        $scope = $filters['export_scope'] ?? 'documents';

        if ($scope === 'fiscal_portfolio') {
            $this->assertFiscalPortfolioFilters($filters, $currentOffice);
            // include_events não se aplica à carteira.
            $data['include_events'] = false;
        }

        if (isset($filters['access_keys']) && count($filters['access_keys']) > BuildExportZipJob::MAX_ACCESS_KEYS) {
            throw ValidationException::withMessages([
                'filters.access_keys' => ['No máximo '.BuildExportZipJob::MAX_ACCESS_KEYS.' chaves por exportação.'],
            ]);
        }

        $export = Export::query()->create([
            'office_id' => $currentOffice->office()->id,
            'user_id' => auth()->id(),
            'status' => 'PENDING',
            'filters' => $filters,
            'include_events' => $data['include_events'] ?? false,
        ]);

        BuildExportZipJob::dispatch($export->id);

        $audit->record('export.create', 'SUCCESS', $export, [
            'filters' => $export->filters,
            'include_events' => $export->include_events,
            'export_scope' => $scope,
        ]);

        return response()->json(['data' => $this->public($export)], 202);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assertFiscalPortfolioFilters(array $filters, CurrentOffice $currentOffice): void
    {
        $module = FiscalModuleKey::tryFromRoute((string) ($filters['module_key'] ?? ''));
        if ($module === null || $module === FiscalModuleKey::Dashboard) {
            throw ValidationException::withMessages([
                'filters.module_key' => ['Módulo fiscal inválido para exportação de carteira.'],
            ]);
        }

        if (isset($filters['situation']) && FiscalSituation::tryFrom((string) $filters['situation']) === null) {
            throw ValidationException::withMessages([
                'filters.situation' => ['Situação fiscal inválida.'],
            ]);
        }

        if (isset($filters['competence']) && ! preg_match('/^\d{4}-\d{2}$/', (string) $filters['competence'])) {
            throw ValidationException::withMessages([
                'filters.competence' => ['Competência deve estar no formato YYYY-MM.'],
            ]);
        }

        $flag = $module->featureFlagKey();
        $officeId = (int) $currentOffice->id();
        if ($flag === null || ! FeatureFlags::isModuleEnabled($flag, $officeId)) {
            abort(403, 'Módulo fiscal desabilitado para este escritório.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        unset($filters['office_id']);

        $scope = isset($filters['export_scope']) && is_string($filters['export_scope'])
            ? strtolower(trim($filters['export_scope']))
            : 'documents';

        if ($scope === 'fiscal_portfolio') {
            return $this->normalizeFiscalPortfolioFilters($filters);
        }

        return $this->normalizeDocumentFilters($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFiscalPortfolioFilters(array $filters): array
    {
        $out = [
            'export_scope' => 'fiscal_portfolio',
        ];

        $module = FiscalModuleKey::tryFromRoute(is_string($filters['module_key'] ?? null)
            ? (string) $filters['module_key']
            : '');
        if ($module !== null && $module !== FiscalModuleKey::Dashboard) {
            $out['module_key'] = $module->value;
        }

        if (! empty($filters['situation']) && is_string($filters['situation'])) {
            $out['situation'] = strtoupper(trim($filters['situation']));
        }

        if (! empty($filters['competence']) && is_string($filters['competence'])) {
            $out['competence'] = trim($filters['competence']);
        }

        if (! empty($filters['q']) && is_string($filters['q'])) {
            $out['q'] = trim($filters['q']);
        }

        if (! empty($filters['submodule']) && is_string($filters['submodule'])) {
            $out['submodule'] = strtoupper(trim($filters['submodule']));
        }

        if (! empty($filters['client_id'])) {
            $out['client_id'] = (int) $filters['client_id'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeDocumentFilters(array $filters): array
    {
        $out = [];

        if (isset($filters['export_scope']) && is_string($filters['export_scope'])) {
            $out['export_scope'] = 'documents';
        }

        foreach (['competence', 'access_key', 'fiscal_role', 'direction', 'status', 'issued_from', 'issued_to'] as $key) {
            if (! empty($filters[$key]) && is_string($filters[$key])) {
                $out[$key] = trim($filters[$key]);
            }
        }

        foreach (['issuer_cnpj', 'taker_cnpj'] as $key) {
            if (! empty($filters[$key]) && is_string($filters[$key])) {
                $out[$key] = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $filters[$key]) ?? '');
            }
        }

        if (! empty($filters['client_id'])) {
            $out['client_id'] = (int) $filters['client_id'];
        }
        if (! empty($filters['establishment_id'])) {
            $out['establishment_id'] = (int) $filters['establishment_id'];
        }

        if (! empty($filters['access_keys']) && is_array($filters['access_keys'])) {
            $keys = array_values(array_unique(array_filter(array_map(
                static fn ($k) => is_string($k) ? trim($k) : '',
                $filters['access_keys'],
            ))));
            if ($keys !== []) {
                $out['access_keys'] = $keys;
            }
        }

        // Preservar kind/kinds se vierem (exports de documentos por tipo).
        if (! empty($filters['kind']) && is_string($filters['kind'])) {
            $out['kind'] = trim($filters['kind']);
        }
        if (! empty($filters['kinds']) && is_array($filters['kinds'])) {
            $out['kinds'] = array_values(array_filter($filters['kinds'], 'is_string'));
        }

        // Manifesto de ausências (outbound mensal) — path interno, nunca do client.
        if (! empty($filters['absence_manifest_path']) && is_string($filters['absence_manifest_path'])) {
            $out['absence_manifest_path'] = $filters['absence_manifest_path'];
        }

        return $out;
    }

    public function download(Export $export, AuditLogger $audit, CurrentOffice $currentOffice): BinaryFileResponse
    {
        // Isolamento por escritório + dono — paths privados sob storage/app/private/exports/{office_id}
        if ((int) $export->office_id !== (int) $currentOffice->id() || $export->user_id !== auth()->id()) {
            abort(404);
        }
        if ($export->status !== 'READY' || ! $export->storage_path || ! is_file($export->storage_path)) {
            abort(404);
        }
        if ($export->expires_at && $export->expires_at->isPast()) {
            abort(410, 'Exportação expirada.');
        }

        // Recusa path fora do diretório privado do office
        $root = realpath(storage_path('app/private/exports/'.$export->office_id));
        $real = realpath($export->storage_path);
        if ($root === false || $real === false
            || (! str_starts_with($real, $root.DIRECTORY_SEPARATOR) && $real !== $root)) {
            abort(404);
        }

        $audit->record('export.download', 'SUCCESS', $export, [
            'files_count' => $export->files_count,
            'byte_size' => $export->byte_size,
        ]);

        return response()->download($export->storage_path, 'export-'.$export->id.'.zip');
    }

    /**
     * @return array<string, mixed>
     */
    private function public(Export $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'filters' => $export->filters,
            'include_events' => $export->include_events,
            'files_count' => $export->files_count,
            'byte_size' => $export->byte_size,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'created_at' => $export->created_at?->toIso8601String(),
            'error_message' => $export->status === 'FAILED' ? $export->error_message : null,
        ];
    }
}
