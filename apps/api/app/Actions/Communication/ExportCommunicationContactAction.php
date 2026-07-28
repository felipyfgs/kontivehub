<?php

namespace App\Actions\Communication;

use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationMessage;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Support\CurrentTenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportCommunicationContactAction
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function execute(CommunicationContact $contact): StreamedResponse
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $contactId = (int) $contact->id;
        $exportedAt = now()->toIso8601String();
        $contactName = $contact->name;
        $isProvisional = (bool) $contact->is_provisional;
        $this->events->record(
            $tenantId,
            'CONTACT_EXPORTED',
            ['contact_id' => $contactId],
            actorMembershipId: $this->currentTenant->realMembership()?->id,
        );

        return response()->streamDownload(
            static function () use (
                $tenantId,
                $contactId,
                $exportedAt,
                $contactName,
                $isProvisional,
            ): void {
                echo '{"exported_at":', self::encode($exportedAt);
                echo ',"contact":{"id":', self::encode($contactId);
                echo ',"name":', self::encode($contactName);
                echo ',"is_provisional":', self::encode($isProvisional);
                echo ',"identities":[';

                $firstIdentity = true;
                foreach (
                    CommunicationIdentity::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('contact_id', $contactId)
                        ->orderBy('id')
                        ->lazyById(100) as $identity
                ) {
                    self::separator($firstIdentity);
                    echo '{"id":', self::encode((int) $identity->id);
                    echo ',"channel":', self::encode(
                        $identity->channel?->value ?? $identity->channel,
                    );
                    echo ',"address":', self::encode($identity->address_encrypted);
                    echo ',"client_ids":[';

                    $firstClient = true;
                    foreach (
                        CommunicationIdentityLink::query()
                            ->withoutGlobalScopes()
                            ->where('tenant_id', $tenantId)
                            ->where('identity_id', $identity->id)
                            ->orderBy('id')
                            ->lazyById(100) as $link
                    ) {
                        self::separator($firstClient);
                        echo self::encode((int) $link->client_id);
                    }

                    echo '],"conversations":[';
                    $firstConversation = true;
                    foreach (
                        CommunicationConversation::query()
                            ->withoutGlobalScopes()
                            ->where('tenant_id', $tenantId)
                            ->where('identity_id', $identity->id)
                            ->orderBy('id')
                            ->lazyById(100) as $conversation
                    ) {
                        self::separator($firstConversation);
                        echo '{"id":', self::encode((int) $conversation->id);
                        echo ',"status":', self::encode(
                            $conversation->status?->value ?? $conversation->status,
                        );
                        echo ',"messages":[';

                        $firstMessage = true;
                        foreach (
                            CommunicationMessage::query()
                                ->withoutGlobalScopes()
                                ->where('tenant_id', $tenantId)
                                ->where('conversation_id', $conversation->id)
                                ->orderBy('occurred_at')
                                ->orderBy('id')
                                ->lazy(100) as $message
                        ) {
                            self::separator($firstMessage);
                            echo '{"id":', self::encode((int) $message->id);
                            echo ',"direction":', self::encode(
                                $message->direction?->value ?? $message->direction,
                            );
                            echo ',"kind":', self::encode(
                                $message->kind?->value ?? $message->kind,
                            );
                            echo ',"body":', self::encode($message->body_encrypted);
                            echo ',"occurred_at":', self::encode(
                                $message->occurred_at?->toIso8601String(),
                            );
                            echo ',"attachments":[';

                            $firstAttachment = true;
                            foreach (
                                CommunicationAttachment::query()
                                    ->withoutGlobalScopes()
                                    ->where('tenant_id', $tenantId)
                                    ->where('message_id', $message->id)
                                    ->orderBy('id')
                                    ->lazyById(100) as $attachment
                            ) {
                                self::separator($firstAttachment);
                                echo self::encode([
                                    'id' => (int) $attachment->id,
                                    'mime_type' => $attachment->mime_type,
                                    'size_bytes' => $attachment->size_bytes,
                                    'sha256' => $attachment->sha256,
                                ]);
                            }
                            echo ']}';
                        }
                        echo ']}';
                    }
                    echo ']}';
                }
                echo ']}}';
            },
            'contato-'.$contactId.'.json',
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    private static function separator(bool &$first): void
    {
        if (! $first) {
            echo ',';
        }
        $first = false;
    }
}
