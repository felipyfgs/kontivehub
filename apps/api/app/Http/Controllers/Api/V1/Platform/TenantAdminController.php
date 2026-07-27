<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Platform\TenantSubscriptionService;
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
        private readonly TenantSubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = Tenant::query()
            ->with('subscription')
            ->orderBy('id');

        if (is_string($status) && $status !== '') {
            $query->whereHas('subscription', fn ($q) => $q->where('status', strtoupper($status)));
        }

        $tenants = $query->get()->map(fn (Tenant $tenant) => $this->sanitizeTenant($tenant));

        return response()->json([
            'data' => $tenants,
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load('subscription');

        return response()->json([
            'data' => $this->sanitizeTenant($tenant),
        ]);
    }

    public function updateSubscription(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(SubscriptionStatus::class)],
            'plan' => ['sometimes', 'string', Rule::enum(SubscriptionPlan::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** Limite negociado de clientes (>200); null limpa o override. Somente plataforma. */
            'negotiated_client_limit' => ['sometimes', 'nullable', 'integer', 'min:201', 'max:100000'],
        ]);

        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->first();

        if ($subscription === null) {
            $plan = isset($validated['plan'])
                ? SubscriptionPlan::from($validated['plan'])
                : SubscriptionPlan::Professional;
            $status = isset($validated['status'])
                ? SubscriptionStatus::from($validated['status'])
                : SubscriptionStatus::Active;

            $subscription = $this->subscriptions->create($tenant, $plan, $status);
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
                            'PENDING_ACTIVATION só é definido na criação de Tenant; use o fluxo de ativação.',
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

        $tenant->load('subscription');

        return response()->json([
            'data' => $this->sanitizeTenant($tenant->fresh(['subscription'])),
        ]);
    }

    /**
     * Metadados comerciais e saúde sanitizada — zero conteúdo fiscal.
     *
     * @return array<string, mixed>
     */
    private function sanitizeTenant(Tenant $tenant): array
    {
        $subscription = $tenant->subscription;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'subscription' => $subscription?->toSanitizedAdminArray(),
            // Contagens agregadas não-fiscais (sem listar clientes/docs)
            'memberships_count' => $tenant->memberships()->count(),
        ];
    }
}
