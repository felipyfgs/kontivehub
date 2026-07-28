<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FiscalModuleKey;
use App\Enums\FiscalSituation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\StoreDocumentExportRequest;
use App\Jobs\BuildExportZipJob;
use App\Models\DocumentExport;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __construct(private readonly TenantAuthorization $authorization) {}

    public function index(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $sort = match ($request->string('sort')->toString()) {
            'status' => 'status',
            'created_at' => 'created_at',
            'files_count' => 'files_count',
            default => 'id',
        };
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $query = DocumentExport::query()
            ->where('tenant_id', $currentTenant->id())
            ->where('user_id', auth()->id())
            ->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (DocumentExport $e) => $this->public($e)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(
        StoreDocumentExportRequest $request,
        CurrentTenant $currentTenant,
        AuditLogger $audit,
    ): JsonResponse {
        $input = $request->exportInput();
        $filters = $this->normalizeFilters($input['filters']);
        $scope = $filters['export_scope'] ?? 'documents';
        $includeEvents = $input['include_events'];

        if ($scope === 'fiscal_portfolio') {
            $this->assertFiscalPortfolioFilters($filters, $currentTenant);
            // include_events não se aplica à carteira.
            $includeEvents = false;
        }

        if (isset($filters['access_keys']) && count($filters['access_keys']) > BuildExportZipJob::MAX_ACCESS_KEYS) {
            throw ValidationException::withMessages([
                'filters.access_keys' => ['No máximo '.BuildExportZipJob::MAX_ACCESS_KEYS.' chaves por exportação.'],
            ]);
        }

        $export = DocumentExport::query()->create([
            'tenant_id' => $currentTenant->tenant()->id,
            'user_id' => auth()->id(),
            'status' => 'PENDING',
            'filters' => $filters,
            'include_events' => $includeEvents,
        ]);

        DB::afterCommit(function () use ($export): void {
            BuildExportZipJob::dispatch($export->id);
        });

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
    private function assertFiscalPortfolioFilters(array $filters, CurrentTenant $currentTenant): void
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
        $tenantId = (int) $currentTenant->id();
        if ($flag === null || ! FeatureFlags::isModuleEnabled($flag, $tenantId)) {
            abort(403, 'Módulo fiscal desabilitado para este escritório.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        unset($filters['tenant_id']);

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

    public function download(DocumentExport $export, AuditLogger $audit, CurrentTenant $currentTenant): BinaryFileResponse
    {
        // Isolamento por escritório + dono — paths privados sob storage/app/private/exports/{tenant_id}
        if ((int) $export->tenant_id !== (int) $currentTenant->id() || $export->user_id !== auth()->id()) {
            abort(404);
        }
        if ($export->status !== 'READY' || ! $export->storage_path || ! is_file($export->storage_path)) {
            abort(404);
        }
        if ($export->expires_at && $export->expires_at->isPast()) {
            abort(410, 'Exportação expirada.');
        }

        // Recusa path fora do diretório privado do tenant
        $root = realpath(storage_path('app/private/exports/'.$export->tenant_id));
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
    private function public(DocumentExport $export): array
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
