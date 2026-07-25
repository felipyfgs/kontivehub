<?php

namespace App\Console\Commands;

use App\Enums\Communication\ConversationStatus;
use App\Models\CommunicationConversation;
use App\Services\Communication\Events\CommunicationEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WakeSnoozedCommunicationCommand extends Command
{
    protected $signature = 'communication:wake-snoozed {--limit=500}';

    protected $description = 'Reabre conversas cujo prazo de adiamento terminou.';

    public function handle(CommunicationEventRecorder $events): int
    {
        $limit = min(2000, max(1, (int) $this->option('limit')));
        $ids = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('status', ConversationStatus::Snoozed->value)
            ->where('snoozed_until', '<=', now())
            ->orderBy('snoozed_until')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $events): void {
                $conversation = CommunicationConversation::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
                if ($conversation === null
                    || $conversation->status !== ConversationStatus::Snoozed
                    || $conversation->snoozed_until?->isFuture()) {
                    return;
                }
                $conversation->forceFill([
                    'status' => ConversationStatus::Open,
                    'snoozed_until' => null,
                    'lock_version' => (int) $conversation->lock_version + 1,
                ])->save();
                $events->record(
                    (int) $conversation->office_id,
                    'CONVERSATION_SNOOZE_ENDED',
                    ['status' => ConversationStatus::Open->value],
                    inboxId: (int) $conversation->inbox_id,
                    conversationId: (int) $conversation->id,
                );
            });
        }

        return self::SUCCESS;
    }
}
