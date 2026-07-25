<?php

namespace App\Http\Controllers\Api\V1\Office;

use App\Enums\ActivationMethod;
use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\OfficeTeamService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestão de equipe do escritório corrente
 * (membership ADMIN real ou PLATFORM_ADMIN em contexto privilegiado).
 */
class OfficeMemberController extends Controller
{
    public function __construct(
        private readonly OfficeTeamService $team,
        private readonly CurrentOffice $currentOffice,
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

        $office = $this->currentOffice->resolve($actor);
        $occupied = $office !== null ? $this->team->occupiedSeats($office) : 0;
        $max = $office?->subscription?->max_users;

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

        // office_id do client é descartado — escopo só via CurrentOffice.
        $request->request->remove('office_id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(OfficeRole::class)],
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
        $request->request->remove('office_id');

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::enum(OfficeRole::class)],
        ]);

        $model = $this->resolveMembership($membership);

        try {
            $data = $this->team->changeRole(
                $actor,
                $model,
                OfficeRole::from($validated['role']),
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
        $request->request->remove('office_id');

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

    private function resolveMembership(int $id): OfficeMembership
    {
        $membership = OfficeMembership::query()->find($id);
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
