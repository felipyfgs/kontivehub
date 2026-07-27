<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\CredentialStatus;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalRepresentationStatus;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\Client;
use App\Models\FgtsDigitalRepresentation;
use App\Models\FgtsDigitalRun;
use App\Models\TenantCredential;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalCredentialResolver;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalReadinessService;
use App\Services\FgtsDigital\FgtsDigitalSessionStore;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FgtsDigitalController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FgtsDigitalReadinessService $readiness,
        private readonly FgtsDigitalPortalService $portal,
        private readonly FgtsDigitalCredentialResolver $credentials,
        private readonly FgtsDigitalSessionStore $sessions,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function coverage(): JsonResponse
    {
        $this->assertCanRead();

        return response()->json(['data' => $this->readiness->coverage()]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $data = $request->validate(['client_id' => ['required', 'integer']]);
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json(['data' => $this->readiness->check($this->currentTenant->tenant(), $client)]);
    }

    public function runs(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $data = $request->validate([
            'client_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $query = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');
        if (isset($data['client_id'])) {
            $query->where('client_id', (int) $data['client_id']);
        }
        $page = $query->paginate((int) ($data['per_page'] ?? 50));
        $page->getCollection()->transform(fn (FgtsDigitalRun $run): array => $run->toPublicArray());

        return response()->json($page);
    }

    public function sync(Request $request): JsonResponse
    {
        $this->assertCanOperate();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'parameters' => ['sometimes', 'array'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $ready = $this->readiness->check($tenant, $client);
        if (! $ready['ready_for_read']) {
            return response()->json([
                'message' => $ready['blockers'][0]['message'] ?? 'FGTS Digital indisponível.',
                'code' => $ready['blockers'][0]['code'] ?? 'FGTS_DIGITAL_NOT_READY',
                'readiness' => $ready,
            ], 423);
        }

        try {
            $run = $this->portal->createQueryRun($tenant, $client, $request->user(), $data['parameters'] ?? []);
            ExecuteFgtsDigitalRunJob::dispatch((int) $tenant->id, (int) $run->id);
        } catch (FgtsDigitalException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $run->toPublicArray()], 202);
    }

    public function syncNow(Request $request): JsonResponse
    {
        $this->assertCanOperate();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'parameters' => ['sometimes', 'array'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $ready = $this->readiness->check($tenant, $client);
        if (! $ready['ready_for_read']) {
            return $this->readinessBlocked($ready);
        }

        try {
            $run = $this->portal->createQueryRun($tenant, $client, $request->user(), $data['parameters'] ?? []);
            $run = $this->portal->executeRun($run);
        } catch (FgtsDigitalException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $run->toPublicArray()]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->assertCanOperate();
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'guide_type' => ['required', 'string', 'in:MONTHLY,TERMINATION,CONSIGNMENT,MIXED,PARAMETERIZED'],
            'parameters' => ['required', 'array'],
            'parameters.competence_period_key' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'parameters.amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'parameters.due_at' => ['sometimes', 'nullable', 'date'],
            'parameters.employee_ids' => ['sometimes', 'array', 'max:500'],
            'parameters.employee_ids.*' => ['string', 'max:80'],
            'parameters.debit_ids' => ['sometimes', 'array', 'max:500'],
            'parameters.debit_ids.*' => ['string', 'max:120'],
        ]);
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $ready = $this->readiness->check($this->currentTenant->tenant(), $client);
        if (! $ready['ready_for_read']) {
            return $this->readinessBlocked($ready);
        }

        try {
            $result = $this->portal->preview(
                $this->currentTenant->tenant(),
                $client,
                $user,
                FgtsDigitalGuideType::from($data['guide_type']),
                $data['parameters'],
            );
        } catch (FgtsDigitalException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => [
            'run' => $result['run']->toPublicArray(),
            'preview_token' => $result['preview_token'],
        ]]);
    }

    public function emit(Request $request, int $run): JsonResponse
    {
        $this->assertCanMutate();
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'confirmation_phrase' => ['required', 'string', 'max:160'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $preview = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($run)
            ->first();
        if ($preview === null) {
            return response()->json(['message' => 'Prévia não encontrada.'], 404);
        }

        try {
            $out = $this->portal->authorizeEmission(
                $tenant,
                $preview,
                $user,
                $data['preview_token'],
                $data['confirmation_phrase'],
            );
            if (! $out['reused']) {
                ExecuteFgtsDigitalRunJob::dispatch((int) $tenant->id, (int) $out['run']->id);
            }
        } catch (FgtsDigitalException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => [
            'run' => $out['run']->toPublicArray(),
            'reused' => $out['reused'],
        ]], $out['reused'] ? 200 : 202);
    }

    public function importSession(Request $request): JsonResponse
    {
        $this->assertCanMutate();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'storage_state' => ['required', 'array'],
            'storage_state.cookies' => ['required', 'array'],
            'storage_state.origins' => ['required', 'array'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $credential = $this->credentials->resolve($tenant, $client, includeMaterial: false);
        if ($credential === null) {
            return response()->json(['message' => 'Credencial/procuração não está pronta.', 'code' => 'FGTS_DIGITAL_CREDENTIAL_MISSING'], 422);
        }

        try {
            $session = $this->sessions->store(
                (int) $tenant->id,
                (int) $client->id,
                $credential['source'],
                $credential['fingerprint'],
                $credential['profile_type'],
                FgtsDigitalCredentialResolver::identifierHash((string) $client->root_cnpj),
                $data['storage_state'],
                $credential['representation_id'],
            );
        } catch (FgtsDigitalException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $session->toPublicArray()], 201);
    }

    public function storeRepresentation(Request $request): JsonResponse
    {
        $this->assertCanMutate();
        if (! (bool) config('fgts_digital.tenant_credential_enabled', false)) {
            return response()->json(['message' => 'Uso do certificado do escritório está desabilitado.', 'code' => 'FGTS_DIGITAL_TENANT_CREDENTIAL_DISABLED'], 403);
        }
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'valid_to' => ['required', 'date', 'after:now'],
            'confirmed' => ['required', 'accepted'],
            'profile_type' => ['sometimes', 'string', 'in:PROCURADOR_PJ,RESPONSAVEL_LEGAL'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $client = $this->client((int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $credential = TenantCredential::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', CredentialStatus::Active->value)
            ->first();
        if ($credential === null) {
            return response()->json(['message' => 'certificado do escritório não está ativo.', 'code' => 'FGTS_DIGITAL_TENANT_CREDENTIAL_MISSING'], 422);
        }

        $representation = FgtsDigitalRepresentation::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'tenant_credential_id' => $credential->id,
            'credential_source' => FgtsDigitalCredentialSource::Tenant,
            'profile_type' => $data['profile_type'] ?? 'PROCURADOR_PJ',
            'target_identifier_hash' => FgtsDigitalCredentialResolver::identifierHash((string) $client->root_cnpj),
            'status' => FgtsDigitalRepresentationStatus::Active,
            'valid_from' => now(),
            'valid_to' => CarbonImmutable::parse($data['valid_to']),
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
            'metadata' => ['source' => 'EXPLICIT_ADMIN_CONFIRMATION'],
        ]);

        return response()->json(['data' => [
            'id' => $representation->id,
            'client_id' => $representation->client_id,
            'status' => $representation->status->value,
            'valid_to' => $representation->valid_to?->toIso8601String(),
            'credential_source' => $representation->credential_source->value,
        ]], 201);
    }

    private function client(int $id): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->tenant()->id)
            ->whereKey($id)
            ->first();
    }

    private function assertCanRead(): void
    {
        if ($this->currentTenant->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanOperate(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function assertCanMutate(): void
    {
        if ($this->currentTenant->role() !== TenantRole::TenantAdmin) {
            abort(403, 'Emissão e credenciais exigem administrador do escritório.');
        }
    }

    private function error(FgtsDigitalException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->codeKey,
            'context' => $e->context,
        ], $e->httpStatus);
    }

    /** @param array<string, mixed> $readiness */
    private function readinessBlocked(array $readiness): JsonResponse
    {
        return response()->json([
            'message' => $readiness['blockers'][0]['message'] ?? 'FGTS Digital indisponível.',
            'code' => $readiness['blockers'][0]['code'] ?? 'FGTS_DIGITAL_NOT_READY',
            'readiness' => $readiness,
        ], 423);
    }
}
