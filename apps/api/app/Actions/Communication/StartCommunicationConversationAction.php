<?php

namespace App\Actions\Communication;

use App\Contracts\CommunicationOutboundMessageWriter;
use App\DTO\Communication\CommunicationMessageCreationData;
use App\Enums\Communication\ConversationStatus;
use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Services\Communication\CommunicationContactCanonicalizer;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Services\Communication\Conversation\CommunicationMessageIdempotency;
use App\Services\Communication\Conversation\CommunicationOutboundConversationGate;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\ProfilePicture\CommunicationProfilePictureRefreshScheduler;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class StartCommunicationConversationAction
{
    public function __construct(
        private CommunicationContactCanonicalizer $contacts,
        private CommunicationConversationCanonicalizer $conversations,
        private CommunicationOutboundConversationGate $gate,
        private CommunicationMessageIdempotency $idempotency,
        private CommunicationOutboundMessageWriter $messages,
        private CommunicationMediaStore $media,
        private CommunicationProfilePictureRefreshScheduler $profilePictures,
    ) {}

    /** @return array{conversation:CommunicationConversation,message:CommunicationMessage,reused:bool,status:int} */
    public function handle(int $contactId, int $identityId, int $inboxId, CommunicationMessageCreationData $data): array
    {
        $stagedObjectId = null;

        try {
            return DB::transaction(function () use ($contactId, $identityId, $inboxId, $data, &$stagedObjectId): array {
                $contact = CommunicationContact::query()->findOrFail($contactId);
                [$canonical, $contactIds] = $this->contacts->lockContactClass($contact);
                $requestedIdentity = CommunicationIdentity::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $canonical->tenant_id)
                    ->whereIn('contact_id', $contactIds)
                    ->whereKey($identityId)
                    ->where('is_active', true)
                    ->whereNull('purged_at')
                    ->firstOrFail();
                $identity = $this->conversations->identity($requestedIdentity);
                if (! in_array((int) $identity->contact_id, $contactIds, true)) {
                    abort(404);
                }
                $identityIds = $this->conversations->identityIds($identity);
                $identityClass = CommunicationIdentity::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $canonical->tenant_id)
                    ->whereIn('id', $identityIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $requestedIdentity = $identityClass->firstWhere('id', $identityId);
                $identity = $identityClass->firstWhere('id', $identity->id);
                if ($identityClass->count() !== count($identityIds)
                    || ! $requestedIdentity instanceof CommunicationIdentity
                    || ! $requestedIdentity->is_active
                    || $requestedIdentity->purged_at !== null
                    || ! $identity instanceof CommunicationIdentity
                    || ! $identity->is_active
                    || $identity->purged_at !== null
                    || $identityClass->contains(fn (CommunicationIdentity $candidate): bool => ! in_array((int) $candidate->contact_id, $contactIds, true))) {
                    abort(404);
                }
                $inbox = CommunicationInbox::query()->lockForUpdate()->findOrFail($inboxId);
                if ((int) $inbox->tenant_id !== (int) $canonical->tenant_id || (int) $identity->tenant_id !== (int) $canonical->tenant_id || $canonical->purged_at !== null) {
                    abort(404);
                }
                $replay = null;
                if ($data->idempotencyKey !== null) {
                    $this->lockIdempotencyKey((int) $inbox->tenant_id, $data->idempotencyKey);
                    $providerId = $this->idempotency->providerId(
                        $data->idempotencyKey,
                        $data->outboundInitiation,
                    );
                    $replay = CommunicationMessage::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $inbox->tenant_id)
                        ->where('provider_message_id', $providerId)
                        ->lockForUpdate()
                        ->first();
                    if ($replay !== null && ((int) $replay->inbox_id !== (int) $inbox->id || ! in_array((int) $replay->identity_id, $identityIds, true))) {
                        throw CommunicationConversationApiException::idempotencyConflict();
                    }
                }
                if ($replay !== null) {
                    $replayConversation = CommunicationConversation::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $inbox->tenant_id)
                        ->where('inbox_id', $inbox->id)
                        ->findOrFail($replay->conversation_id);
                    $conversation = $this->conversations->conversation($replayConversation);
                    $result = $this->messages->handle($conversation, $data);

                    return ['conversation' => $conversation->fresh(), 'message' => $result->message, 'reused' => true, 'status' => $result->httpStatus];
                }

                $this->gate->assertAllowed($inbox, $identity);
                $conversation = CommunicationConversation::query()->where('tenant_id', $inbox->tenant_id)->where('inbox_id', $inbox->id)->whereIn('identity_id', $identityIds)->whereNull('merged_into_conversation_id')->orderByRaw("CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END")->orderByDesc('last_message_at')->lockForUpdate()->first();
                $reused = $conversation !== null;
                if ($conversation === null) {
                    $conversation = CommunicationConversation::query()->create(['tenant_id' => $inbox->tenant_id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id, 'status' => ConversationStatus::Open, 'lock_version' => 0]);
                } elseif ($conversation->status === ConversationStatus::Resolved) {
                    $conversation->forceFill(['status' => ConversationStatus::Open, 'resolved_at' => null, 'lock_version' => (int) $conversation->lock_version + 1])->save();
                }
                $this->profilePictures->schedule($inbox, $identity);
                $result = $this->messages->handle($conversation, $data);
                if ($result->httpStatus !== 200) {
                    $stagedObjectId = $result->message->attachments->first()?->object_id;
                }

                return ['conversation' => $conversation->fresh(), 'message' => $result->message, 'reused' => $reused, 'status' => $result->httpStatus];
            });
        } catch (Throwable $error) {
            if (is_string($stagedObjectId) && $stagedObjectId !== '') {
                try {
                    $this->media->delete($stagedObjectId);
                } catch (Throwable) {
                    // Nunca mascara a falha transacional original; o store já é idempotente para limpeza.
                }
            }

            throw $error;
        }
    }

    private function lockIdempotencyKey(int $tenantId, string $idempotencyKey): void
    {
        $digest = hash('sha256', 'communication:outbound-initiation:'.$tenantId.':'.$idempotencyKey);
        DB::select('SELECT pg_advisory_xact_lock(CAST(? AS INTEGER), CAST(? AS INTEGER))', [
            $this->signedInt32(substr($digest, 0, 8)),
            $this->signedInt32(substr($digest, 8, 8)),
        ]);
    }

    private function signedInt32(string $hex): int
    {
        $value = (int) hexdec($hex);

        return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
    }
}
