<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationEvent;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunicationDataController extends Controller
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationMediaStore $media,
        private readonly CommunicationEventRecorder $events,
        private readonly CommunicationFlowRunControlService $flowRuns,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertView($actor);
        $after = max(0, $request->integer('after', 0));
        $limit = min(500, max(1, $request->integer('limit', 200)));
        $visibleInboxIds = $this->access->visibleInboxIds($actor);
        $query = CommunicationEvent::query()->where('id', '>', $after)
            ->where(function ($builder) use ($visibleInboxIds): void {
                $builder->whereIn('inbox_id', $visibleInboxIds);
                if ($this->currentTenant->role() === TenantRole::TenantAdmin || $this->currentTenant->isPlatformPrivileged()) {
                    $builder->orWhereNull('inbox_id');
                }
            })
            ->orderBy('id');
        $rows = (clone $query)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn (CommunicationEvent $event) => [
                'cursor' => (int) $event->id,
                'type' => $event->type,
                'inbox_id' => $event->inbox_id,
                'conversation_id' => $event->conversation_id,
                'message_id' => $event->message_id,
                'payload' => $event->payload ?? [],
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->values(),
            'meta' => [
                'next_cursor' => $rows->last()?->id ?? $after,
                'has_more' => $hasMore,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function downloadAttachment(Request $request, int $attachment): StreamedResponse
    {
        return $this->streamAttachment($this->authorizedAttachment($request, $attachment), 'attachment');
    }

    public function previewAttachment(Request $request, int $attachment): StreamedResponse
    {
        $model = $this->authorizedAttachment($request, $attachment);
        abort_unless(
            str_starts_with((string) $model->mime_type, 'image/')
                || str_starts_with((string) $model->mime_type, 'audio/')
                || str_starts_with((string) $model->mime_type, 'video/'),
            415,
            'Este tipo de anexo não possui preview inline.',
        );

        return $this->streamAttachment($model, 'inline');
    }

    private function authorizedAttachment(Request $request, int $attachment): CommunicationAttachment
    {
        $model = CommunicationAttachment::query()->with('message.inbox')->findOrFail($attachment);
        $this->access->assertView($this->actor($request), $model->message->inbox);
        abort_if(
            $model->purged_at !== null
                || $model->message->purged_at !== null
                || $model->message->revoked_at !== null
                || ! $this->media->exists($model->object_id),
            404,
        );

        return $model;
    }

    private function streamAttachment(CommunicationAttachment $model, string $disposition): StreamedResponse
    {
        $metadata = is_array($model->storage_context) ? $model->storage_context : [
            'tenant_id' => (int) $model->tenant_id,
            'inbox_id' => (int) $model->message->inbox_id,
            'gateway_event_id' => (string) $model->message->gateway_event_id,
            'sha256' => $model->sha256,
        ];
        $name = $model->original_name_encrypted ?: 'anexo-'.$model->id;
        $name = basename(str_replace('\\', '/', (string) $name));
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'anexo-'.$model->id;

        return response()->stream(function () use ($model, $metadata): void {
            foreach ($this->media->readChunks($model->object_id, $metadata) as $chunk) {
                echo $chunk;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => $model->mime_type,
            'Content-Length' => (string) $model->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $name, $fallback),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function exportContact(Request $request, int $contact): StreamedResponse
    {
        $model = CommunicationContact::query()->with([
            'identities.clientLinks.client',
            'identities.conversations.inbox',
            'identities.conversations.messages.attachments',
        ])->findOrFail($contact);
        $this->access->assertManageContacts($this->actor($request), $model);
        $this->events->record(
            (int) $model->tenant_id,
            'CONTACT_EXPORTED',
            ['contact_id' => (int) $model->id],
            actorMembershipId: $this->currentTenant->realMembership()?->id,
        );

        return response()->streamDownload(static function () use ($model): void {
            $payload = [
                'exported_at' => now()->toIso8601String(),
                'contact' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'is_provisional' => (bool) $model->is_provisional,
                    'identities' => $model->identities->map(fn ($identity) => [
                        'id' => $identity->id,
                        'channel' => $identity->channel?->value ?? $identity->channel,
                        'address' => $identity->address_encrypted,
                        'client_ids' => $identity->clientLinks->pluck('client_id')->values(),
                        'conversations' => $identity->conversations->map(fn ($conversation) => [
                            'id' => $conversation->id,
                            'status' => $conversation->status?->value ?? $conversation->status,
                            'messages' => $conversation->messages->map(fn ($message) => [
                                'id' => $message->id,
                                'direction' => $message->direction?->value ?? $message->direction,
                                'kind' => $message->kind?->value ?? $message->kind,
                                'body' => $message->body_encrypted,
                                'occurred_at' => $message->occurred_at?->toIso8601String(),
                                'attachments' => $message->attachments->map(fn ($attachment) => [
                                    'id' => $attachment->id,
                                    'mime_type' => $attachment->mime_type,
                                    'size_bytes' => $attachment->size_bytes,
                                    'sha256' => $attachment->sha256,
                                ])->values(),
                            ])->values(),
                        ])->values(),
                    ])->values(),
                ],
            ];
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'contato-'.$model->id.'.json', [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function purgeContact(Request $request, int $contact): JsonResponse
    {
        $model = CommunicationContact::query()->with([
            'identities.conversations.messages.attachments',
        ])->findOrFail($contact);
        $this->access->assertManageContacts($this->actor($request), $model);
        $objectIds = $model->identities->flatMap(fn ($identity) => $identity->conversations)
            ->flatMap(fn ($conversation) => $conversation->messages)
            ->flatMap(fn ($message) => $message->attachments)
            ->pluck('object_id')->unique()->values()->all();
        $now = now();
        $tombstone = hash('sha256', 'communication-contact-purge|'.$model->id.'|'.random_bytes(32));

        DB::transaction(function () use ($model, $now, $tombstone): void {
            foreach ($model->identities as $identity) {
                foreach ($identity->conversations as $conversation) {
                    foreach ($conversation->messages as $message) {
                        $message->forceFill([
                            'body_encrypted' => null,
                            'metadata' => null,
                            'content_digest' => hash('sha256', 'purged-message|'.$message->id.'|'.$tombstone),
                            'purged_at' => $now,
                        ])->save();
                        foreach ($message->attachments as $attachment) {
                            $attachment->forceFill(['original_name_encrypted' => null, 'purged_at' => $now])->save();
                        }
                    }
                    $conversation->forceFill([
                        'status' => ConversationStatus::Resolved,
                        'resolved_at' => $now,
                        'purged_at' => $now,
                        'tombstone_digest' => hash('sha256', 'purged-conversation|'.$conversation->id.'|'.$tombstone),
                        'lock_version' => (int) $conversation->lock_version + 1,
                    ])->save();
                    $this->flowRuns->terminateActiveForConversation(
                        (int) $conversation->id,
                        FlowRunStatus::Purged,
                        'contact_purge',
                    );
                }
                $identity->forceFill([
                    'address_encrypted' => null,
                    'address_hash' => hash('sha256', 'purged-identity|'.$identity->id.'|'.$tombstone),
                    'address_masked' => '[removido]',
                    'is_active' => false,
                    'purged_at' => $now,
                ])->save();
            }
            $model->forceFill([
                'name' => null,
                'metadata' => null,
                'is_provisional' => false,
                'is_active' => false,
                'purged_at' => $now,
            ])->save();
            $this->events->record(
                (int) $model->tenant_id,
                'CONTACT_PURGED',
                ['contact_id' => (int) $model->id, 'tombstone_digest' => $tombstone],
                actorMembershipId: $this->currentTenant->realMembership()?->id,
            );
        });

        foreach ($objectIds as $objectId) {
            $this->media->delete((string) $objectId);
        }

        return response()->json(['data' => [
            'contact_id' => $model->id,
            'purged_at' => $now->toIso8601String(),
            'deleted_blobs' => count($objectIds),
            'tombstone_digest' => $tombstone,
        ]]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
