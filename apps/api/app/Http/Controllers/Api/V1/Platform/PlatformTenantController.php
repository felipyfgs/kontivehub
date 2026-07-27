<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\SubscriptionPlan;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rules\ValidCnpj;
use App\Services\Activation\ActivationException;
use App\Services\Activation\CorrectPendingRecipientService;
use App\Services\Activation\CreatePendingTenantService;
use App\Services\Activation\RegenerateActivationService;
use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administração de Tenants (criação pendente, detalhe, regeneração, correção do 1º ADMIN).
 */
class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly CreatePendingTenantService $createTenant,
        private readonly RegenerateActivationService $regenerate,
        private readonly CorrectPendingRecipientService $correctRecipient,
        private readonly DemoEnvironmentGuard $demoEnvironment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('lifecycle_status');

        $query = Tenant::query()
            ->with(['subscription', 'institutionalProfile'])
            ->where(fn ($visible) => $visible
                ->where('is_active', true)
                ->orWhere('lifecycle_status', TenantLifecycleStatus::PendingActivation->value))
            ->orderByDesc('id');

        // O tenant sentinela existe apenas para isolamento das fixtures locais,
        // não como escritório administrado criado nesta superfície.
        if ($this->demoEnvironment->isAllowedEnvironment()) {
            $sentinelSlug = trim($this->demoEnvironment->sentinelTenantSlug());
            if ($sentinelSlug !== '') {
                $query->where('slug', '!=', $sentinelSlug);
            }
        }

        // Filtro de ciclo de vida (PENDING_ACTIVATION / ACTIVE). "all" = todos os visíveis.
        if (is_string($status) && $status !== '' && strtoupper($status) !== 'ALL') {
            $query->where('lifecycle_status', strtoupper($status));
        }

        $data = $query->get()->map(fn (Tenant $tenant) => $this->summarize($tenant));

        return response()->json(['data' => $data]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['subscription', 'institutionalProfile', 'memberships.user']);

        return response()->json([
            'data' => $this->createTenant->sanitizedTenantPayload($tenant)['tenant'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // CNPJ institucional pode ficar em branco e ser preenchido depois.
        $profile = $request->input('profile');
        if (is_array($profile) && array_key_exists('cnpj', $profile) && trim((string) $profile['cnpj']) === '') {
            $profile['cnpj'] = null;
            $request->merge(['profile' => $profile]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'profile' => ['required', 'array'],
            'profile.cnpj' => ['nullable', 'string', new ValidCnpj],
            'profile.legal_name' => ['required', 'string', 'max:255'],
            'profile.institutional_email' => ['required', 'email', 'max:255'],
            'profile.institutional_phone' => ['required', 'string', 'max:40'],
            'plan' => ['required', 'string', Rule::enum(SubscriptionPlan::class)],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        try {
            $payload = $this->createTenant->create($validated, $actor);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        $status = ($payload['credential_delivery'] ?? null) === 'regeneration_required' ? 200 : 201;

        return $this->noStoreJson(['data' => $payload], $status);
    }

    public function regenerateActivation(Request $request, Tenant $tenant): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantRole::TenantAdmin)
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            return response()->json(['message' => 'Primeiro administrador não encontrado.', 'code' => 'not_found'], 404);
        }

        $activation = AccountActivation::query()
            ->where('tenant_membership_id', $membership->id)
            ->where('purpose', ActivationPurpose::TenantFirstAdmin)
            ->whereNull('consumed_at')
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();

        if ($activation === null) {
            return response()->json(['message' => 'Nenhuma ativação pendente.', 'code' => 'not_found'], 404);
        }

        try {
            $payload = $this->regenerate->regenerate(
                $activation,
                ActivationMethod::from($validated['method']),
                $actor,
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson(['data' => $payload]);
    }

    public function updateFirstAdmin(Request $request, Tenant $tenant): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        try {
            $payload = $this->correctRecipient->correctTenantFirstAdmin(
                $tenant,
                $validated['name'],
                $validated['email'],
                ActivationMethod::from($validated['method']),
                $actor,
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson(['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Tenant $tenant): array
    {
        $lifecycle = $tenant->lifecycle_status instanceof TenantLifecycleStatus
            ? $tenant->lifecycle_status->value
            : (string) ($tenant->lifecycle_status ?? 'ACTIVE');

        $activation = AccountActivation::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', ActivationPurpose::TenantFirstAdmin)
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'lifecycle_status' => $lifecycle,
            'subscription' => $tenant->subscription?->toSanitizedAdminArray(),
            'activation' => $activation?->toSanitizedArray(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    private function activationError(ActivationException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function noStoreJson(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}
