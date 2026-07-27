<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Enums\ActivationMethod;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\TenantTeamService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestão de equipe do escritório corrente
 * (membership ADMIN real ou PLATFORM_ADMIN em contexto privilegiado).
 */
class TenantMemberController extends Controller
{
    public function __construct(
        private readonly TenantTeamService $team,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $data = $this->team->list($actor);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        $tenant = $this->currentTenant->resolve($actor);
        $occupied = $tenant !== null ? $this->team->occupiedSeats($tenant) : 0;
        $max = $tenant?->subscription?->max_users;

        return response()->json([
            'data' => $data,
            'meta' => [
                'occupied_seats' => $occupied,
                'max_users' => $max,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // tenant_id do client é descartado — escopo só via CurrentTenant.
        $request->request->remove('tenant_id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(TenantRole::class)],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        try {
            $payload = $this->team->createMember($actor, $validated);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson(['data' => $payload], 201);
    }

    public function update(Request $request, int $membership): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $request->request->remove('tenant_id');

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::enum(TenantRole::class)],
        ]);

        $model = $this->resolveMembership($membership);

        try {
            $data = $this->team->changeRole(
                $actor,
                $model,
                TenantRole::from($validated['role']),
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return response()->json(['data' => $data]);
    }

    public function updateRecipient(Request $request, int $membership): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $request->request->remove('tenant_id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        $model = $this->resolveMembership($membership);

        try {
            $payload = $this->team->correctRecipient(
                $actor,
                $model,
                $validated['name'],
                $validated['email'],
                ActivationMethod::from($validated['method']),
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson(['data' => $payload]);
    }

    public function deactivate(Request $request, int $membership): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $model = $this->resolveMembership($membership);

        try {
            $data = $this->team->deactivate($actor, $model);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return response()->json(['data' => $data]);
    }

    public function reactivate(Request $request, int $membership): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'method' => ['sometimes', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        $model = $this->resolveMembership($membership);
        $method = isset($validated['method'])
            ? ActivationMethod::from($validated['method'])
            : ActivationMethod::ManualLink;

        try {
            $payload = $this->team->reactivate($actor, $model, $method);
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        $status = ($payload['credential_delivery'] ?? null) === 'delivered' ? 200 : 200;

        return $this->noStoreJson(['data' => $payload], $status);
    }

    public function regenerateActivation(Request $request, int $membership): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ]);

        $model = $this->resolveMembership($membership);

        try {
            $payload = $this->team->regenerateActivation(
                $actor,
                $model,
                ActivationMethod::from($validated['method']),
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson(['data' => $payload]);
    }

    private function resolveMembership(int $id): TenantMembership
    {
        $membership = TenantMembership::query()->find($id);
        if ($membership === null) {
            abort(404, 'Membro não encontrado.');
        }

        return $membership;
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
