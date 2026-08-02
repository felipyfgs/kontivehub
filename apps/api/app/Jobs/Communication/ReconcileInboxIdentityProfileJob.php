<?php

namespace App\Jobs\Communication;

use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Services\Communication\Contact\InboxIdentityProfileReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

final class ReconcileInboxIdentityProfileJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public readonly string $observedAt;

    public readonly string $reconciliationId;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $inboxId,
        public readonly int $identityId,
        ?string $observedAt = null,
        ?string $reconciliationId = null,
    ) {
        $this->observedAt = $observedAt ?? now()->utc()->format('Y-m-d\\TH:i:s.u\\Z');
        $this->reconciliationId = $reconciliationId ?? 'reconcile-'.strtolower((string) Str::ulid());
        $this->onQueue('communication');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->inboxId.':'.$this->identityId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->inboxLockKey()))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(150),
        ];
    }

    public function handle(InboxIdentityProfileReconciler $reconciler): void
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()
            ->whereKey($this->inboxId)
            ->where('tenant_id', $this->tenantId)
            ->first();
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->whereKey($this->identityId)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if ($inbox === null || $identity === null) {
            return;
        }

        $reconciler->reconcileIdentity(
            $inbox,
            $identity,
            $this->observedAt,
            $this->reconciliationId,
        );
    }

    public function tags(): array
    {
        return ['communication', 'contact-profile-reconciliation', 'directed'];
    }

    private function inboxLockKey(): string
    {
        return 'communication-contact-profiles:'.$this->tenantId.':'.$this->inboxId;
    }
}
