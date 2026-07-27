<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Services\Integra\TaxProxyPowerService;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Onboarding tenant-scoped: Autor, Termo, procurações, saúde sanitizada.
 * NÃO importa clients HTTP globais nem models de contrato global.
 * Nunca retorna XML, PFX ou tokens.
 */
class TenantSerproAuthorizationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly IntegraEligibilityService $eligibility,
        private readonly TenantIntegraHealthService $health,
        private readonly SerproTenantActionableStatusService $actionableStatus,
        private readonly ClientProcuracaoSyncService $procuracaoSync,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();
        $env = $this->environment($request);

        $auth = $this->authorizations->getOrCreate($tenant, $env);
        $tenantStatus = $this->actionableStatus->forTenant($tenant, $env);

        return response()->json([
            'data' => $auth->toPublicArray(),
            // Saúde sanitizada (sem detalhes OAuth/mTLS/contrato global)
            'platform_health' => $this->health->forEnvironment($env),
            'onboarding' => $tenantStatus['onboarding'],
            'actionable' => $tenantStatus['actionable'],
            'platform_available' => $tenantStatus['platform_available'],
            'term_representation_strategy' => $this->authorizations->representationStrategy($env)->value,
        ]);
    }

    public function configureAuthor(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'author_identity_type' => ['required', 'string', Rule::enum(AuthorIdentityType::class)],
            'author_identity' => ['required', 'string', 'max:14'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'certificate_mode' => ['sometimes', 'string', Rule::enum(AuthorCertificateMode::class)],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $auth = $this->authorizations->configureAuthor(
                $tenant,
                $env,
                AuthorIdentityType::from($data['author_identity_type']),
                $data['author_identity'],
                $data['author_name'] ?? null,
                isset($data['certificate_mode'])
                    ? AuthorCertificateMode::from($data['certificate_mode'])
                    : AuthorCertificateMode::ExternalSignature,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()]);
    }

    /**
     * Gera draft canônico não assinado (fluxo externo certificado/A3).
     * Não devolve o XML — use downloadTermoDraft.
     */
    public function generateTermoDraft(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'vigencia' => ['sometimes', 'date'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $result = $this->authorizations->generateTermoDraft(
                $tenant,
                $env,
                isset($data['vigencia']) ? $data['vigencia'] : null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $result['auth']->toPublicArray(),
            'draft_sha256' => $result['draft_sha256'],
        ], 201);
    }

    /**
     * Download protegido do draft (admin + senha recente). XML não-assinado.
     */
    public function downloadTermoDraft(Request $request): Response
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();
        $env = $this->environment($request);

        try {
            $xml = $this->authorizations->getTermoDraftXml($tenant, $env);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $this->audit->record('serpro.authorization.termo_draft_download', 'SUCCESS', null, [
            'environment' => $env->value,
            'bytes' => strlen($xml),
        ], $request->user()?->id, $tenant->id);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="termo-autorizacao-draft.xml"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadTermo(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'termo_xml' => ['required_without:termo_file', 'string'],
            'termo_file' => ['required_without:termo_xml', 'file', 'max:2048'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        $xml = $data['termo_xml'] ?? null;
        if ($xml === null && isset($data['termo_file'])) {
            $xml = file_get_contents($data['termo_file']->getRealPath()) ?: '';
        }

        try {
            $auth = $this->authorizations->uploadTermo($tenant, $env, (string) $xml, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Falha ao processar Termo.'], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()], 201);
    }

    /**
     * Dispara job de assinatura com certificado gerenciado (consentimento versionado).
     */
    public function signTermoManagedCertificate(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'consent' => ['required', 'accepted'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $auth = $this->authorizations->dispatchManagedCertificateSign(
                $tenant,
                $env,
                true,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $auth->toPublicArray(),
            'message' => 'Assinatura com certificado enfileirada.',
        ], 202);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();
        $env = $this->environment($request);

        try {
            $auth = $this->authorizations->refreshProcuradorToken($tenant, $env, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()]);
    }

    public function listProxyPowers(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in([
                'id',
                'client_id',
                'power_code',
                'system_code',
                'status',
            ])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $sort = $data['sort'] ?? 'id';
        $direction = $data['direction'] ?? 'desc';

        $query = TaxProxyPower::query()
            ->where('tenant_id', $tenant->id);

        if (isset($data['client_id'])) {
            $query->where('client_id', $data['client_id']);
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        $paginator = $query->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (TaxProxyPower $power): array => $power->toPublicArray())
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function importProxyPower(Request $request): JsonResponse
    {
        $this->assertAdmin();
        // F-3.3: projeção oficial não admite importação/override manual.
        try {
            $this->procuracaoSync->rejectManualOverride();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Importação manual proibida.'], 422);
    }

    public function syncProxyPowers(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'client_id' => ['required', 'integer'],
            'power_code' => ['nullable', 'string', 'max:120'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        $client = Client::query()->where('tenant_id', $tenant->id)->findOrFail($data['client_id']);

        try {
            // Sync oficial completo → atualiza TaxProxyPower + ClientProcuracaoSync
            $result = $this->procuracaoSync->syncOfficial(
                $tenant,
                $client,
                $env,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => array_map(fn ($p) => $p->toPublicArray(), $result['powers']),
            'procuracao' => $result['sync']->toClientProjection(),
        ]);
    }

    public function eligibility(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:40'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        $client = Client::query()->where('tenant_id', $tenant->id)->findOrFail($data['client_id']);

        $result = $this->eligibility->evaluate(
            $tenant,
            $client,
            $data['solution_code'],
            $data['service_code'],
            $data['operation_code'],
            $env,
            $request->user(),
            $data['module'] ?? null,
        );

        return response()->json(['data' => $result->toArray()]);
    }

    public function platformHealth(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $env = $this->environment($request);

        return response()->json([
            'data' => $this->health->forEnvironment($env),
        ]);
    }

    private function environment(Request $request): SerproEnvironment
    {
        $raw = $request->query('environment') ?? $request->input('environment');
        if (is_string($raw) && $raw !== '') {
            return SerproEnvironment::tryFrom(strtoupper($raw))
                ?? SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));
        }

        return SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));
    }

    private function assertAdmin(): void
    {
        $role = $this->currentTenant->role();
        if ($role !== TenantRole::TenantAdmin) {
            abort(403, 'Ação restrita a ADMIN do escritório.');
        }
    }

    private function assertAdminOrOperator(): void
    {
        $role = $this->currentTenant->role();
        if (! in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)) {
            abort(403, 'Ação restrita a membros autorizados do escritório.');
        }
    }
}
