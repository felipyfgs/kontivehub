<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\OfficeRole;
use App\Enums\SerproEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Services\Integra\TaxProxyPowerService;
use App\Services\Integra\TenantIntegraHealthService;
use App\Support\CurrentOffice;
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
class OfficeSerproAuthorizationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeSerproAuthorizationService $authorizations,
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
        $office = $this->currentOffice->office();
        $env = $this->environment($request);

        $auth = $this->authorizations->getOrCreate($office, $env);
        $tenantStatus = $this->actionableStatus->forOffice($office, $env);

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
        $office = $this->currentOffice->office();

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
                $office,
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
     * Gera draft canônico não assinado (fluxo externo A1/A3).
     * Não devolve o XML — use downloadTermoDraft.
     */
    public function generateTermoDraft(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'vigencia' => ['sometimes', 'date'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $result = $this->authorizations->generateTermoDraft(
                $office,
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
     * Download protegido do draft (ADMIN + 2FA middleware). XML não-assinado.
     */
    public function downloadTermoDraft(Request $request): Response
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();
        $env = $this->environment($request);

        try {
            $xml = $this->authorizations->getTermoDraftXml($office, $env);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $this->audit->record('serpro.authorization.termo_draft_download', 'SUCCESS', null, [
            'environment' => $env->value,
            'bytes' => strlen($xml),
        ], $request->user()?->id, $office->id);

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
        $office = $this->currentOffice->office();

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
            $auth = $this->authorizations->uploadTermo($office, $env, (string) $xml, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Falha ao processar Termo.'], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()], 201);
    }

    /**
     * Dispara job de assinatura com A1 gerenciado (consentimento versionado).
     */
    public function signTermoManagedA1(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'consent' => ['required', 'accepted'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $auth = $this->authorizations->dispatchManagedA1Sign(
                $office,
                $env,
                true,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $auth->toPublicArray(),
            'message' => 'Assinatura A1 gerenciada enfileirada.',
        ], 202);
    }

    public function storeAuthorA1(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent' => ['required', 'accepted'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler PFX.');
            }
            $auth = $this->authorizations->storeManagedAuthorA1(
                $office,
                $env,
                $binary,
                $data['password'],
                true,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('serpro.authorization.author_a1', 'FAILED', null, [
                'message' => $e->getMessage(),
            ], $request->user()?->id, $office->id);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Falha ao armazenar A1 do Autor.'], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()], 201);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();
        $env = $this->environment($request);

        try {
            $auth = $this->authorizations->refreshProcuradorToken($office, $env, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $auth->toPublicArray()]);
    }

    public function listProxyPowers(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $office = $this->currentOffice->office();

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
            ->where('office_id', $office->id);

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
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'client_id' => ['required', 'integer'],
            'power_code' => ['nullable', 'string', 'max:120'],
        ]);

        $env = isset($data['environment'])
            ? SerproEnvironment::from($data['environment'])
            : SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));

        $client = Client::query()->where('office_id', $office->id)->findOrFail($data['client_id']);

        try {
            // Sync oficial completo → atualiza TaxProxyPower + ClientProcuracaoSnapshot
            $result = $this->procuracaoSync->syncOfficial(
                $office,
                $client,
                $env,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => array_map(fn ($p) => $p->toPublicArray(), $result['powers']),
            'procuracao' => $result['snapshot']->toClientProjection(),
        ]);
    }

    public function eligibility(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $office = $this->currentOffice->office();

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

        $client = Client::query()->where('office_id', $office->id)->findOrFail($data['client_id']);

        $result = $this->eligibility->evaluate(
            $office,
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
        $role = $this->currentOffice->role();
        if ($role !== OfficeRole::Admin) {
            abort(403, 'Ação restrita a ADMIN do escritório.');
        }
    }

    private function assertAdminOrOperator(): void
    {
        $role = $this->currentOffice->role();
        if (! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação restrita a ADMIN/OPERATOR do escritório.');
        }
    }
}
