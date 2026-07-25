<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\OfficeSubscription;
use App\Services\Platform\OfficeSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Administração global sanitizada de tenants (PLATFORM_ADMIN).
 * NÃO expõe conteúdo fiscal, mensagens, relatórios ou evidências.
 */
class TenantAdminController extends Controller
{
    public function __construct(
        private readonly OfficeSubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = Office::query()
            ->with('subscription')
            ->orderBy('id');

        if (is_string($status) && $status !== '') {
            $query->whereHas('subscription', fn ($q) => $q->where('status', strtoupper($status)));
        }

        $tenants = $query->get()->map(fn (Office $office) => $this->sanitizeTenant($office));

        return response()->json([
            'data' => $tenants,
        ]);
    }

    public function show(Office $office): JsonResponse
    {
        $office->load('subscription');

        return response()->json([
            'data' => $this->sanitizeTenant($office),
        ]);
    }

    public function updateSubscription(Request $request, Office $office): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(SubscriptionStatus::class)],
            'plan' => ['sometimes', 'string', Rule::enum(SubscriptionPlan::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** Limite negociado de clientes (>200); null limpa o override. Somente plataforma. */
            'negotiated_client_limit' => ['sometimes', 'nullable', 'integer', 'min:201', 'max:100000'],
        ]);

        $subscription = OfficeSubscription::query()->where('office_id', $office->id)->first();

        if ($subscription === null) {
            $plan = isset($validated['plan'])
                ? SubscriptionPlan::from($validated['plan'])
                : SubscriptionPlan::Professional;
            $status = isset($validated['status'])
                ? SubscriptionStatus::from($validated['status'])
                : SubscriptionStatus::Active;

            $subscription = $this->subscriptions->create($office, $plan, $status);
        } else {
            try {
                if (isset($validated['plan'])) {
                    $subscription = $this->subscriptions->changePlan(
                        $subscription,
                        SubscriptionPlan::from($validated['plan']),
                    );
                }

                if (isset($validated['status'])) {
                    $to = SubscriptionStatus::from($validated['status']);
                    $subscription = match ($to) {
                        SubscriptionStatus::Active => $subscription->status === SubscriptionStatus::Active
                            ? $subscription
                            : (
                                in_array($subscription->status, [SubscriptionStatus::Suspended, SubscriptionStatus::PastDue], true)
                                    ? $this->subscriptions->resume($subscription)
                                    : $this->subscriptions->activate($subscription)
                            ),
                        SubscriptionStatus::PastDue => $this->subscriptions->markPastDue($subscription),
                        SubscriptionStatus::Suspended => $this->subscriptions->suspend(
                            $subscription,
                            $validated['notes'] ?? null,
                        ),
                        SubscriptionStatus::Canceled => $this->subscriptions->cancel(
                            $subscription,
                            $validated['notes'] ?? null,
                        ),
                        SubscriptionStatus::Trial => $this->subscriptions->transition(
                            $subscription,
                            SubscriptionStatus::Trial,
                            notes: $validated['notes'] ?? null,
                        ),
                        SubscriptionStatus::PendingActivation => throw new InvalidArgumentException(
                            'PENDING_ACTIVATION só é definido na criação de Office; use o fluxo de ativação.',
                        ),
                    };
                }

                if (array_key_exists('negotiated_client_limit', $validated)) {
                    $limit = $validated['negotiated_client_limit'];
                    if ($limit === null) {
                        $subscription->negotiated_client_limit = null;
                        $subscription->save();
                    } else {
                        $subscription = $this->subscriptions->setNegotiatedClientLimit(
                            $subscription,
                            (int) $limit,
                            $request->user()?->id,
                        );
                    }
                }

                if (isset($validated['notes']) && ! isset($validated['status'])) {
                    $subscription->notes = $validated['notes'];
                    $subscription->save();
                }
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        $office->load('subscription');

        return response()->json([
            'data' => $this->sanitizeTenant($office->fresh(['subscription'])),
        ]);
    }

    /**
     * Metadados comerciais e saúde sanitizada — zero conteúdo fiscal.
     *
     * @return array<string, mixed>
     */
    private function sanitizeTenant(Office $office): array
    {
        $subscription = $office->subscription;

        return [
            'id' => $office->id,
            'name' => $office->name,
            'slug' => $office->slug,
            'is_active' => $office->is_active,
            'created_at' => $office->created_at?->toIso8601String(),
            'subscription' => $subscription?->toSanitizedAdminArray(),
            // Contagens agregadas não-fiscais (sem listar clientes/docs)
            'memberships_count' => $office->memberships()->count(),
        ];
    }
}
