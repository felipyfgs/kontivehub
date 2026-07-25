<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\SendMessageRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Http\Resources\Communication\CommunicationConversationResource;
use App\Http\Resources\Communication\CommunicationMessageResource;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationConversation;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CommunicationConversationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly CommunicationAccess $access,
        private readonly CommunicationAvailability $availability,
        private readonly CommunicationOutboxService $outbox,
        private readonly CommunicationEventRecorder $events,
        private readonly CommunicationMediaStore $media,
        private readonly CommunicationFlowRunControlService $flowRuns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertView($actor);
        $inboxIds = $this->access->visibleInboxIds($actor);
        $query = CommunicationConversation::query()
            ->whereIn('inbox_id', $inboxIds)
            ->with(['identity.contact', 'clients', 'labels', 'latestMessage.attachments'])
            ->withCount('messages');

        if ($request->filled('inbox_id')) {
            $query->where('inbox_id', $request->integer('inbox_id'));
        }
        if ($status = ConversationStatus::tryFrom(strtoupper($request->string('status')->toString()))) {
            $query->where('status', $status->value);
        }
        if ($request->filled('assignee_membership_id')) {
            $query->where('assignee_membership_id', $request->integer('assignee_membership_id'));
        }
        if ($request->filled('work_department_id')) {
            $query->where('work_department_id', $request->integer('work_department_id'));
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('assignee_membership_id');
        }
        if ($search = trim($request->string('q')->toString())) {
            $messageConversationIds = CommunicationMessage::query()
                ->whereIn('inbox_id', $inboxIds)
                ->whereNull('purged_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->limit(500)
                ->get(['id', 'conversation_id', 'body_encrypted'])
                ->filter(fn (CommunicationMessage $message): bool => str_contains(
                    mb_strtolower((string) $message->body_encrypted),
                    mb_strtolower($search),
                ))
                ->pluck('conversation_id')
                ->all();
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder
                ->whereIn('id', $messageConversationIds)
                ->orWhereHas('identity.contact', fn ($contacts) => $contacts->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle]))
                ->orWhereHas('identity', fn ($identities) => $identities->where('address_masked', 'like', '%'.$search.'%'))
                ->orWhereHas('clients', fn ($clients) => $clients->where(fn ($clientNames) => $clientNames
                    ->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(legal_name, '')) LIKE ?", [$needle]))));
        }

        $paginator = $query->orderByDesc('priority')->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 30))));

        return response()->json([
            'data' => CommunicationConversationResource::collection(collect($paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $conversation): CommunicationConversationResource
    {
        $model = $this->conversation($conversation);
        $this->access->assertView($this->actor($request), $model->inbox);

        return new CommunicationConversationResource($model->load([
            'identity.contact',
            'clients',
            'labels',
            'messages.attachments',
        ]));
    }

    public function update(UpdateConversationRequest $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);
        $this->access->assertReply($this->actor($request), $model->inbox);
        $data = $request->validated();
        $attributes = [];
        foreach (['priority', 'assignee_membership_id', 'work_department_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }
        if (array_key_exists('assignee_membership_id', $attributes) && $attributes['assignee_membership_id'] !== null) {
            $membership = OfficeMembership::query()->where('office_id', $model->office_id)
                ->where('is_active', true)->findOrFail((int) $attributes['assignee_membership_id']);
            $hasInbox = CommunicationInboxMember::query()->withoutGlobalScopes()
                ->where('inbox_id', $model->inbox_id)
                ->where('office_membership_id', $membership->id)
                ->where('is_active', true)->exists();
            abort_unless($hasInbox || $membership->role?->isAdmin(), 422, 'Responsável sem acesso à inbox.');
        }
        if (array_key_exists('work_department_id', $attributes) && $attributes['work_department_id'] !== null) {
            WorkDepartment::query()->withoutGlobalScopes()->where('office_id', $model->office_id)
                ->findOrFail((int) $attributes['work_department_id']);
        }
        if (isset($data['status'])) {
            $status = ConversationStatus::from((string) $data['status']);
            $attributes['status'] = $status;
            $attributes['resolved_at'] = $status === ConversationStatus::Resolved ? now() : null;
            $attributes['snoozed_until'] = $status === ConversationStatus::Snoozed
                ? $data['snoozed_until']
                : null;
            if ($status === ConversationStatus::Snoozed && empty($data['snoozed_until'])) {
                return response()->json(['message' => 'Informe snoozed_until para adiar a conversa.'], 422);
            }
        } elseif (array_key_exists('snoozed_until', $data)) {
            $attributes['snoozed_until'] = $data['snoozed_until'];
            $attributes['status'] = ConversationStatus::Snoozed;
        }
        $attributes['lock_version'] = (int) $data['lock_version'] + 1;
        $changed = CommunicationConversation::query()->whereKey($model->id)
            ->where('lock_version', $data['lock_version'])->update($attributes);
        if ($changed !== 1) {
            return response()->json(['message' => 'Conversa foi alterada por outro usuário.', 'code' => 'version_conflict'], 409);
        }
        $updated = $model->fresh();
        $this->events->record(
            (int) $updated->office_id,
            'CONVERSATION_UPDATED',
            ['status' => $updated->status->value, 'lock_version' => (int) $updated->lock_version],
            inboxId: (int) $updated->inbox_id,
            conversationId: (int) $updated->id,
            actorMembershipId: $this->currentOffice->realMembership()?->id,
        );

        return (new CommunicationConversationResource($updated->load(['identity.contact', 'clients', 'labels'])))->response();
    }

    public function send(SendMessageRequest $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);
        $this->access->assertReply($this->actor($request), $model->inbox);
        $data = $request->validated();
        $internal = (bool) ($data['internal_note'] ?? false);
        $upload = $request->file('file');
        if ($internal && $upload !== null) {
            return response()->json(['message' => 'Notas internas não aceitam anexos.', 'code' => 'internal_note_attachment'], 422);
        }
        $this->availability->assertEnabled($model->inbox, ! $internal);
        $body = trim((string) ($data['body'] ?? ''));
        $providerId = $internal ? null : $this->providerId($data['idempotency_key'] ?? null);
        $uploadPath = $upload?->getRealPath();
        $uploadDigest = is_string($uploadPath) && $uploadPath !== '' ? hash_file('sha256', $uploadPath) : null;
        $uploadDigest = is_string($uploadDigest) ? $uploadDigest : null;
        $requestedKind = isset($data['kind']) ? MessageKind::from((string) $data['kind']) : null;
        if ($requestedKind === MessageKind::Unsupported) {
            return response()->json([
                'message' => 'Este tipo de mensagem não é suportado para envio.',
                'code' => 'MESSAGE_KIND_UNSUPPORTED',
            ], 422);
        }
        if ((bool) ($data['gif'] ?? false)) {
            return response()->json([
                'message' => 'GIF animado ainda não possui builder outbound contratual.',
                'code' => 'MESSAGE_KIND_UNSUPPORTED',
            ], 422);
        }
        $mime = $upload !== null
            ? $this->normalizeUploadMime(
                $this->safeMime((string) $upload->getMimeType()),
                $this->safeMime((string) $upload->getClientMimeType()),
                $requestedKind,
            )
            : null;
        $richPayload = array_filter([
            'link_preview' => $data['link_preview'] ?? null,
            'location' => $data['location'] ?? null,
            'contact' => $data['contact'] ?? null,
            'poll' => $data['poll'] ?? null,
            'interactive' => $data['interactive'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        $familyPayloads = array_intersect_key($richPayload, array_flip(['location', 'contact', 'poll', 'interactive']));
        if (count($familyPayloads) > 1 || ($upload !== null && $richPayload !== [])
            || (isset($richPayload['link_preview']) && $familyPayloads !== [])) {
            throw ValidationException::withMessages([
                'kind' => 'Envie exatamente um DTO de família por mensagem.',
            ]);
        }
        if ($internal && ($upload !== null || $richPayload !== [])) {
            throw ValidationException::withMessages([
                'internal_note' => 'Nota interna aceita somente texto, sem mídia ou conteúdo rico.',
            ]);
        }
        $kind = $this->resolveMessageKind($internal, $mime, $requestedKind, $richPayload);
        $ptt = (bool) ($data['ptt'] ?? false);
        if ($ptt && ($upload === null || $kind !== MessageKind::Audio)) {
            throw ValidationException::withMessages(['ptt' => 'PTT exige um arquivo de áudio.']);
        }
        $replyTo = isset($data['reply_to_message_id'])
            ? CommunicationMessage::query()->where('conversation_id', $model->id)
                ->findOrFail((int) $data['reply_to_message_id'])
            : null;
        $replyProviderId = null;
        if (! $internal && $replyTo instanceof CommunicationMessage) {
            $replyProviderId = trim((string) $replyTo->provider_message_id);
            if ($replyProviderId === '') {
                throw ValidationException::withMessages([
                    'reply_to_message_id' => 'A mensagem citada ainda não possui identificador remoto.',
                ]);
            }
        }
        $contentDigest = hash('sha256', implode('|', [
            $kind->value,
            $body,
            $uploadDigest ?? '',
            $replyProviderId ?? '',
            $ptt ? 'ptt' : 'media',
            json_encode($richPayload, JSON_THROW_ON_ERROR),
        ]));

        if ($providerId !== null) {
            $existing = CommunicationMessage::query()->where('inbox_id', $model->inbox_id)
                ->where('provider_message_id', $providerId)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->content_digest, $contentDigest)) {
                    return response()->json(['message' => 'Idempotency key reutilizada com outro conteúdo.', 'code' => 'idempotency_conflict'], 409);
                }

                return (new CommunicationMessageResource($existing->load('attachments')))->response();
            }
        }

        $stored = null;
        $storageContext = null;
        if ($upload !== null && is_string($uploadPath) && $uploadPath !== '') {
            $stream = fopen($uploadPath, 'rb');
            abort_unless(is_resource($stream), 422, 'Arquivo inválido.');
            $storageContext = [
                'office_id' => (int) $model->office_id,
                'inbox_id' => (int) $model->inbox_id,
                'upload_id' => (string) Str::uuid(),
            ];
            try {
                $stored = $this->media->putStream($stream, $storageContext);
            } finally {
                fclose($stream);
            }
            if ($uploadDigest === null || ! hash_equals($uploadDigest, $stored['sha256'])) {
                $this->media->delete($stored['object_id']);
                abort(422, 'Falha de integridade no anexo.');
            }
        }

        try {
            $message = DB::transaction(function () use (
                $model,
                $internal,
                $body,
                $providerId,
                $contentDigest,
                $kind,
                $mime,
                $ptt,
                $replyTo,
                $replyProviderId,
                $upload,
                $stored,
                $storageContext,
                $richPayload,
            ): CommunicationMessage {
                $message = CommunicationMessage::query()->create([
                    'office_id' => $model->office_id,
                    'inbox_id' => $model->inbox_id,
                    'conversation_id' => $model->id,
                    'identity_id' => $model->identity_id,
                    'reply_to_message_id' => $replyTo?->id,
                    'author_membership_id' => $this->currentOffice->realMembership()?->id,
                    'direction' => $internal ? MessageDirection::Internal : MessageDirection::Outbound,
                    'kind' => $kind,
                    'provider_type' => $this->outboundProviderType($kind, $richPayload),
                    'source' => MessageSource::Human,
                    'status' => $internal ? MessageStatus::Sent : MessageStatus::Queued,
                    'body_encrypted' => $body !== '' ? $body : null,
                    'content_encrypted' => $richPayload !== [] ? $this->outboundContent($richPayload) : null,
                    'provider_message_id' => $providerId,
                    'content_digest' => $contentDigest,
                    'occurred_at' => now(),
                    'sent_at' => $internal ? now() : null,
                ]);
                $attachment = null;
                if ($upload !== null && is_array($stored) && is_array($storageContext)) {
                    $attachment = CommunicationAttachment::query()->create([
                        'office_id' => $model->office_id,
                        'message_id' => $message->id,
                        'object_id' => $stored['object_id'],
                        'original_name_encrypted' => $this->safeFilename($upload->getClientOriginalName()),
                        'mime_type' => $mime ?? 'application/octet-stream',
                        'size_bytes' => $stored['size_bytes'],
                        'sha256' => $stored['sha256'],
                        'storage_context' => $storageContext,
                    ]);
                }
                $model->forceFill([
                    'last_message_at' => $message->occurred_at,
                    'lock_version' => (int) $model->lock_version + 1,
                ])->save();
                if (! $internal) {
                    $payload = [
                        'to' => $model->identity->address_encrypted,
                        'kind' => $kind->value,
                    ];
                    if ($kind === MessageKind::Text) {
                        $payload['text'] = $body;
                        if (isset($richPayload['link_preview'])) {
                            $payload['link_preview'] = $richPayload['link_preview'];
                        }
                    } elseif ($body !== '' && ! in_array($kind, [MessageKind::Audio, MessageKind::Sticker], true)) {
                        $payload['caption'] = $body;
                    }
                    foreach (['location', 'contact', 'poll', 'interactive'] as $field) {
                        if (isset($richPayload[$field])) {
                            $payload[$field] = $richPayload[$field];
                        }
                    }
                    if ($replyTo instanceof CommunicationMessage && $replyProviderId !== null) {
                        $payload['reply_to'] = array_filter([
                            'message_id' => $replyProviderId,
                            'sender' => $replyTo->direction === MessageDirection::Inbound
                                ? (string) $model->identity->address_encrypted
                                : null,
                        ], static fn (mixed $value): bool => $value !== null && $value !== '');
                    }
                    if ($attachment instanceof CommunicationAttachment) {
                        $payload['media'] = [
                            'attachment_id' => (int) $attachment->id,
                            'mime_type' => $attachment->mime_type,
                            'filename' => (string) $attachment->original_name_encrypted,
                            'size_bytes' => (int) $attachment->size_bytes,
                            'sha256' => $attachment->sha256,
                            'ptt' => $ptt,
                        ];
                    }
                    $this->outbox->enqueue($model->inbox, GatewayCommandType::SendMessage, $payload, $message);
                    $this->flowRuns->handoffActiveForConversation(
                        (int) $model->id,
                        $this->currentOffice->realMembership(),
                        'human_outbound',
                    );
                }
                $this->events->record(
                    (int) $model->office_id,
                    $internal ? 'INTERNAL_NOTE_CREATED' : 'MESSAGE_QUEUED',
                    [
                        'message_id' => (int) $message->id,
                        'direction' => $message->direction->value,
                        'kind' => $message->kind->value,
                        'has_media' => $attachment !== null,
                    ],
                    inboxId: (int) $model->inbox_id,
                    conversationId: (int) $model->id,
                    messageId: (int) $message->id,
                    actorMembershipId: $this->currentOffice->realMembership()?->id,
                );

                return $message;
            });
        } catch (Throwable $error) {
            if (is_array($stored)) {
                $this->media->delete($stored['object_id']);
            }
            throw $error;
        }

        return (new CommunicationMessageResource($message->load('attachments')))->response()->setStatusCode($internal ? 201 : 202);
    }

    public function addLabel(Request $request, int $conversation, int $label): JsonResponse
    {
        $model = $this->conversation($conversation);
        $this->access->assertReply($this->actor($request), $model->inbox);
        $labelModel = CommunicationLabel::query()->findOrFail($label);
        $model->labels()->syncWithoutDetaching([$labelModel->id => [
            'office_id' => $model->office_id,
            'assigned_by_membership_id' => $this->currentOffice->realMembership()?->id,
        ]]);

        return response()->json(['data' => ['label_id' => $labelModel->id]], 201);
    }

    public function removeLabel(Request $request, int $conversation, int $label): JsonResponse
    {
        $model = $this->conversation($conversation);
        $this->access->assertReply($this->actor($request), $model->inbox);
        $model->labels()->detach($label);

        return response()->json(status: 204);
    }

    private function conversation(int $id): CommunicationConversation
    {
        return CommunicationConversation::query()->with(['inbox', 'identity'])->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function providerId(?string $idempotencyKey): string
    {
        return $idempotencyKey === null
            ? 'message-'.strtolower((string) Str::ulid())
            : 'message-'.substr(hash('sha256', $idempotencyKey), 0, 40);
    }

    private function resolveMessageKind(
        bool $internal,
        ?string $mime,
        ?MessageKind $requested,
        array $richPayload = [],
    ): MessageKind {
        if ($internal) {
            if ($requested !== null && $requested !== MessageKind::Text) {
                throw ValidationException::withMessages(['kind' => 'Nota interna aceita somente texto.']);
            }

            return MessageKind::Note;
        }
        if ($mime === null) {
            $expectedRich = match (true) {
                isset($richPayload['location']) => MessageKind::Location,
                isset($richPayload['contact']) => MessageKind::Contact,
                isset($richPayload['poll']) => MessageKind::Poll,
                isset($richPayload['interactive']) => MessageKind::Interactive,
                default => MessageKind::Text,
            };
            if ($requested !== null && $requested !== $expectedRich) {
                throw ValidationException::withMessages(['kind' => 'O tipo informado não corresponde ao DTO enviado.']);
            }

            return $expectedRich;
        }

        $kind = $requested ?? match (true) {
            str_starts_with($mime, 'image/') => MessageKind::Image,
            str_starts_with($mime, 'audio/') => MessageKind::Audio,
            str_starts_with($mime, 'video/') => MessageKind::Video,
            default => MessageKind::Document,
        };
        $matches = match ($kind) {
            MessageKind::Image => str_starts_with($mime, 'image/'),
            MessageKind::Audio => str_starts_with($mime, 'audio/'),
            MessageKind::Video => str_starts_with($mime, 'video/'),
            MessageKind::Document => $mime !== '',
            MessageKind::Sticker => $mime === 'image/webp',
            default => false,
        };
        if (! $matches) {
            throw ValidationException::withMessages([
                'kind' => "O tipo {$kind->value} não corresponde ao MIME {$mime}.",
            ]);
        }

        return $kind;
    }

    /** @param array<string,mixed> $richPayload */
    private function outboundProviderType(MessageKind $kind, array $richPayload): string
    {
        return match ($kind) {
            MessageKind::Text => isset($richPayload['link_preview']) ? 'extendedTextMessage' : 'conversation',
            MessageKind::Image => 'imageMessage',
            MessageKind::Audio => 'audioMessage',
            MessageKind::Video => 'videoMessage',
            MessageKind::Document => 'documentMessage',
            MessageKind::Sticker => 'stickerMessage',
            MessageKind::Location => 'locationMessage',
            MessageKind::Contact => 'contactMessage',
            MessageKind::Poll => 'pollCreationMessageV3',
            MessageKind::Interactive => 'interactiveMessage',
            MessageKind::Note => 'internalNote',
            MessageKind::Unsupported => throw new \DomainException('MESSAGE_KIND_UNSUPPORTED'),
        };
    }

    /** @param array<string,mixed> $richPayload @return array<string,mixed> */
    private function outboundContent(array $richPayload): array
    {
        $content = $richPayload;
        if (isset($content['contact'])) {
            $content['contacts'] = [$content['contact']];
            unset($content['contact']);
        }

        return $content;
    }

    private function safeMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) ? $mime : 'application/octet-stream';
    }

    private function normalizeUploadMime(
        string $detectedMime,
        string $clientMime,
        ?MessageKind $requestedKind,
    ): string {
        if ($requestedKind === MessageKind::Audio
            && $detectedMime === 'video/webm'
            && $clientMime === 'audio/webm') {
            return 'audio/webm';
        }

        return $detectedMime;
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';

        return mb_substr($filename !== '' ? $filename : 'anexo', 0, 255);
    }
}
