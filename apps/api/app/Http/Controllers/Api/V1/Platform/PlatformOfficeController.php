<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\OfficeLifecycleStatus;
use App\Enums\OfficeRole;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Rules\ValidCnpj;
use App\Services\Activation\ActivationException;
use App\Services\Activation\CorrectPendingRecipientService;
use App\Services\Activation\CreatePendingOfficeService;
use App\Services\Activation\RegenerateActivationService;
use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administração de Offices (criação pendente, detalhe, regeneração, correção do 1º ADMIN).
 */
class PlatformOfficeController extends Controller
{
    public function __construct(
        private readonly CreatePendingOfficeService $createOffice,
        private readonly RegenerateActivationService $regenerate,
        private readonly CorrectPendingRecipientService $correctRecipient,
        private readonly DemoEnvironmentGuard $demoEnvironment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('lifecycle_status');

        $query = Office::query()
            ->with(['subscription', 'institutionalProfile'])
            ->where(fn ($visible) => $visible
                ->where('is_active', true)
                ->orWhere('lifecycle_status', OfficeLifecycleStatus::PendingActivation->value))
            ->orderByDesc('id');

        // O tenant sentinela existe apenas para isolamento das fixtures locais,
        // não como escritório administrado criado nesta superfície.
        if ($this->demoEnvironment->isAllowedEnvironment()) {
            $sentinelSlug = trim($this->demoEnvironment->sentinelOfficeSlug());
            if ($sentinelSlug !== '') {
                $query->where('slug', '!=', $sentinelSlug);
            }
        }

        // Filtro de ciclo de vida (PENDING_ACTIVATION / ACTIVE). "all" = todos os visíveis.
        if (is_string($status) && $status !== '' && strtoupper($status) !== 'ALL') {
            $query->where('lifecycle_status', strtoupper($status));
        }

        $data = $query->get()->map(fn (Office $office) => $this->summarize($office));

        return response()->json(['data' => $data]);
    }

    public function show(Office $office): JsonResponse
    {
        $office->load(['subscription', 'institutionalProfile', 'memberships.user']);

        return response()->json([
            'data' => $this->createOffice->sanitizedOfficePayload($office)['office'],
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
            $payload = $this->createOffice->create($validated, $actor);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        $status = ($payload['credential_delivery'] ?? null) === 'regeneration_required' ? 200 : 201;

        return $this->noStoreJson(['data' => $payload], $status);
    }

    public function regenerateActivation(Request $request, Office $office): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        $membership = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->where('role', OfficeRole::Admin)
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            return response()->json(['message' => 'Primeiro administrador não encontrado.', 'code' => 'not_found'], 404);
        }

        $activation = AccountActivation::query()
            ->where('office_membership_id', $membership->id)
            ->where('purpose', ActivationPurpose::OfficeFirstAdmin)
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

    public function updateFirstAdmin(Request $request, Office $office): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        try {
            $payload = $this->correctRecipient->correctOfficeFirstAdmin(
                $office,
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
    private function summarize(Office $office): array
    {
        $lifecycle = $office->lifecycle_status instanceof OfficeLifecycleStatus
            ? $office->lifecycle_status->value
            : (string) ($office->lifecycle_status ?? 'ACTIVE');

        $activation = AccountActivation::query()
            ->where('office_id', $office->id)
            ->where('purpose', ActivationPurpose::OfficeFirstAdmin)
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $office->id,
            'name' => $office->name,
            'slug' => $office->slug,
            'is_active' => $office->is_active,
            'lifecycle_status' => $lifecycle,
            'subscription' => $office->subscription?->toSanitizedAdminArray(),
            'activation' => $activation?->toSanitizedArray(),
            'created_at' => $office->created_at?->toIso8601String(),
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
