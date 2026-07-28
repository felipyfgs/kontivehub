<?php

namespace App\Actions\Platform;

use App\DTO\Platform\TenantSubscriptionUpdateData;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\TenantSubscriptionUpdateException;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Platform\TenantSubscriptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateTenantSubscriptionAction
{
    public function __construct(
        private TenantSubscriptionService $subscriptions,
    ) {}

    public function __invoke(
        Tenant $tenant,
        TenantSubscriptionUpdateData $data,
        User $actor,
    ): TenantSubscription {
        try {
            return DB::transaction(
                fn (): TenantSubscription => $this->update($tenant, $data, $actor),
                3,
            );
        } catch (InvalidArgumentException $error) {
            throw new TenantSubscriptionUpdateException($error->getMessage());
        }
    }

    private function update(
        Tenant $tenant,
        TenantSubscriptionUpdateData $data,
        User $actor,
    ): TenantSubscription {
        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            return $this->subscriptions->create(
                $tenant,
                $data->plan ?? SubscriptionPlan::Professional,
                $data->status ?? SubscriptionStatus::Active,
            );
        }

        if ($data->hasPlan && $data->plan instanceof SubscriptionPlan) {
            $subscription = $this->subscriptions->changePlan($subscription, $data->plan);
        }

        if ($data->hasStatus && $data->status instanceof SubscriptionStatus) {
            $subscription = $this->transitionStatus($subscription, $data->status, $data->notes);
        }

        if ($data->hasNegotiatedClientLimit) {
            if ($data->negotiatedClientLimit === null) {
                $subscription->forceFill(['negotiated_client_limit' => null])->save();
            } else {
                $subscription = $this->subscriptions->setNegotiatedClientLimit(
                    $subscription,
                    $data->negotiatedClientLimit,
                    $actor->id,
                );
            }
        }

        if ($data->hasNotes && ! $data->hasStatus) {
            $subscription->forceFill(['notes' => $data->notes])->save();
        }

        return $subscription->refresh();
    }

    private function transitionStatus(
        TenantSubscription $subscription,
        SubscriptionStatus $status,
        ?string $notes,
    ): TenantSubscription {
        return match ($status) {
            SubscriptionStatus::Active => $subscription->status === SubscriptionStatus::Active
                ? $subscription
                : (
                    in_array($subscription->status, [SubscriptionStatus::Suspended, SubscriptionStatus::PastDue], true)
                        ? $this->subscriptions->resume($subscription)
                        : $this->subscriptions->activate($subscription)
                ),
            SubscriptionStatus::PastDue => $this->subscriptions->markPastDue($subscription),
            SubscriptionStatus::Suspended => $this->subscriptions->suspend($subscription, $notes),
            SubscriptionStatus::Canceled => $this->subscriptions->cancel($subscription, $notes),
            SubscriptionStatus::Trial => $this->subscriptions->transition(
                $subscription,
                SubscriptionStatus::Trial,
                notes: $notes,
            ),
            SubscriptionStatus::PendingActivation => throw new InvalidArgumentException(
                'PENDING_ACTIVATION só é definido na criação de Tenant; use o fluxo de ativação.',
            ),
        };
    }
}
