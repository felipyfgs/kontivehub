<?php

namespace App\Actions\Communication;

use App\Contracts\CommunicationOutboundMessageWriter;
use App\DTO\Communication\MessageBatchCreationData;
use App\DTO\Communication\MessageBatchCreationResult;
use App\DTO\Communication\MessageBatchItemContext;
use App\DTO\Communication\MessageCreationData;
use App\Enums\Communication\OutboundCapabilityUnavailableReason;
use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageBatch;
use App\Services\Communication\Catalog\OutboundCapabilityEvaluator;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\Outbox\OutboxService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateMessageBatchAction
{
    private const IDEMPOTENCY_CONSTRAINT = 'communication_message_batches_idempotency_uidx';

    public function __construct(
        private CommunicationOutboundMessageWriter $messages,
        private ConversationCanonicalizer $canonicalizer,
        private MediaStore $media,
        private OutboundCapabilityEvaluator $capabilities,
        private OutboxService $outbox,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        MessageBatchCreationData $data,
    ): MessageBatchCreationResult {
        $conversation = $this->canonicalizer->conversation($conversation)->loadMissing('inbox');
        $existing = $this->existing($conversation, $data->clientBatchId);
        if ($existing !== null) {
            return $this->idempotentResult($existing, $data->requestDigest);
        }
        $this->capabilities->assertFeatureEnabled(
            'media_batch',
            OutboundCapabilityUnavailableReason::MessageBatchUnimplemented,
        );

        $storedObjectIds = [];
        try {
            return DB::transaction(function () use ($conversation, $data, &$storedObjectIds): MessageBatchCreationResult {
                $lockedConversation = $this->canonicalizer->lockConversation($conversation);
                $existing = CommunicationMessageBatch::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('client_batch_id', $data->clientBatchId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $this->idempotentResult($existing, $data->requestDigest);
                }

                $batch = CommunicationMessageBatch::query()->create([
                    'tenant_id' => $lockedConversation->tenant_id,
                    'inbox_id' => $lockedConversation->inbox_id,
                    'conversation_id' => $lockedConversation->id,
                    'client_batch_id' => $data->clientBatchId,
                    'request_digest' => $data->requestDigest,
                    'status' => 'QUEUED',
                    'item_count' => count($data->items),
                ]);
                $gatewayBatchId = $batch->gatewayBatchId();
                $size = count($data->items);
                $gatewayItems = [];

                foreach ($data->items as $position => $item) {
                    $result = $this->messages->handle(
                        $lockedConversation,
                        $item,
                        new MessageBatchItemContext(
                            batchId: (int) $batch->id,
                            gatewayBatchId: $gatewayBatchId,
                            position: $position,
                            size: $size,
                        ),
                    );
                    $message = $result->message;
                    foreach ($message->attachments as $attachment) {
                        $storedObjectIds[] = (string) $attachment->object_id;
                    }
                    $gatewayItems[] = $this->gatewayItem(
                        $lockedConversation,
                        $message,
                        $item,
                        $gatewayBatchId,
                        $position,
                        $size,
                    );
                }

                $this->outbox->enqueueBatch(
                    $lockedConversation->inbox,
                    [
                        'batch_id' => $gatewayBatchId,
                        'size' => $size,
                        'album_native' => false,
                        'items' => $gatewayItems,
                    ],
                    $batch,
                    commandId: 'command-'.$gatewayBatchId,
                );

                return new MessageBatchCreationResult(
                    batch: $batch->load('messages.attachments'),
                    httpStatus: 202,
                );
            });
        } catch (QueryException $error) {
            foreach (array_unique($storedObjectIds) as $objectId) {
                $this->media->delete($objectId);
            }
            if ($this->isIdempotencyConflict($error)) {
                $existing = $this->existing($conversation, $data->clientBatchId);
                if ($existing !== null) {
                    return $this->idempotentResult($existing, $data->requestDigest);
                }
            }
            throw $error;
        } catch (Throwable $error) {
            foreach (array_unique($storedObjectIds) as $objectId) {
                $this->media->delete($objectId);
            }
            throw $error;
        }
    }

    /**
     * @return array{
     *     batch_id:string,
     *     position:int,
     *     size:int,
     *     provider_message_id:string,
     *     message:array<string, mixed>
     * }
     */
    private function gatewayItem(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        MessageCreationData $data,
        string $gatewayBatchId,
        int $position,
        int $size,
    ): array {
        $attachment = $message->attachments->first();
        if (! $attachment instanceof CommunicationAttachment
            || trim((string) $message->provider_message_id) === '') {
            throw new \LogicException('Item de lote sem mídia ou identificador remoto estável.');
        }

        $payload = [
            'to' => (string) $conversation->identity->address_encrypted,
            'kind' => $message->kind->value,
            'media' => [
                'attachment_id' => (int) $attachment->id,
                'mime_type' => (string) $attachment->mime_type,
                'filename' => (string) $attachment->original_name_encrypted,
                'size_bytes' => (int) $attachment->size_bytes,
                'sha256' => (string) $attachment->sha256,
                'ptt' => false,
                'gif' => $data->gif,
                'ptv' => $data->ptv,
                'view_once' => $data->viewOnce,
            ],
        ];
        if ($data->body !== '') {
            $payload['caption'] = $data->body;
        }

        return [
            'batch_id' => $gatewayBatchId,
            'position' => $position,
            'size' => $size,
            'provider_message_id' => (string) $message->provider_message_id,
            'message' => $payload,
        ];
    }

    private function existing(
        CommunicationConversation $conversation,
        string $clientBatchId,
    ): ?CommunicationMessageBatch {
        return CommunicationMessageBatch::query()
            ->where('conversation_id', $conversation->id)
            ->where('client_batch_id', $clientBatchId)
            ->first();
    }

    private function idempotentResult(
        CommunicationMessageBatch $batch,
        string $requestDigest,
    ): MessageBatchCreationResult {
        if (! hash_equals((string) $batch->request_digest, $requestDigest)) {
            throw CommunicationConversationApiException::idempotencyConflict();
        }

        return new MessageBatchCreationResult(
            batch: $batch->load('messages.attachments'),
            httpStatus: 200,
        );
    }

    private function isIdempotencyConflict(QueryException $error): bool
    {
        return (string) $error->getCode() === '23505'
            && str_contains((string) ($error->errorInfo[2] ?? ''), self::IDEMPOTENCY_CONSTRAINT);
    }
}
