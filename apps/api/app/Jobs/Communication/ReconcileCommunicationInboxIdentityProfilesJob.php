<?php

namespace App\Jobs\Communication;

use App\Models\CommunicationInbox;
use App\Services\Communication\Contact\CommunicationInboxIdentityProfileReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

final class ReconcileCommunicationInboxIdentityProfilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public readonly string $observedAt;

    public readonly string $reconciliationId;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $inboxId,
        public readonly int $afterIdentityId = 0,
        ?string $observedAt = null,
        ?string $reconciliationId = null,
    ) {
        $this->observedAt = $observedAt ?? now()->utc()->format('Y-m-d\\TH:i:s.u\\Z');
        $this->reconciliationId = $reconciliationId ?? 'reconcile-'.strtolower((string) Str::ulid());
        $this->afterCommit();
    }

    public function handle(CommunicationInboxIdentityProfileReconciler $reconciler): void
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()
            ->whereKey($this->inboxId)->where('tenant_id', $this->tenantId)->first();
        if ($inbox === null) {
            return;
        }
        $result = $reconciler->reconcile(
            $inbox,
            $this->afterIdentityId,
            $this->observedAt,
            $this->reconciliationId,
        );
        if ($result['next_identity_id'] !== null) {
            self::dispatch(
                $this->tenantId,
                $this->inboxId,
                $result['next_identity_id'],
                $this->observedAt,
                $this->reconciliationId,
            );
        }
    }

    public function tags(): array
    {
        return ['communication', 'contact-profile-reconciliation'];
    }
}
