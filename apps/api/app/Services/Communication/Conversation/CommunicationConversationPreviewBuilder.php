<?php

namespace App\Services\Communication\Conversation;

use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationMessage;

final class CommunicationConversationPreviewBuilder
{
    /**
     * @return array{kind: string, text?: string, attachment_kind?: string, direction?: string}|null
     */
    public function fromMessage(?CommunicationMessage $message): ?array
    {
        if ($message === null || $message->purged_at !== null) {
            return null;
        }
        if ($message->revoked_at !== null) {
            return [
                'kind' => 'revoked',
                'text' => 'Mensagem apagada',
                'direction' => $this->direction($message),
            ];
        }

        $kind = $message->kind instanceof MessageKind
            ? $message->kind
            : MessageKind::tryFrom((string) $message->kind);

        if ($kind === MessageKind::Text || $kind === MessageKind::Note) {
            $body = trim((string) $message->body_encrypted);
            if ($body === '' && is_array($message->content_encrypted)) {
                $body = trim((string) ($message->content_encrypted['text']
                    ?? $message->content_encrypted['caption']
                    ?? ''));
            }

            return [
                'kind' => 'text',
                'text' => mb_substr($body !== '' ? $body : 'Mensagem', 0, 160),
                'direction' => $this->direction($message),
            ];
        }

        $labels = [
            MessageKind::Image->value => 'Imagem',
            MessageKind::Audio->value => 'Áudio',
            MessageKind::Video->value => 'Vídeo',
            MessageKind::Document->value => 'Documento',
            MessageKind::Sticker->value => 'Figurinha',
            MessageKind::Location->value => 'Localização',
            MessageKind::Contact->value => 'Contato',
            MessageKind::Poll->value => 'Enquete',
            MessageKind::Interactive->value => 'Interativo',
        ];
        $key = $kind?->value ?? 'UNKNOWN';

        return [
            'kind' => 'attachment',
            'attachment_kind' => strtolower($key),
            'text' => $labels[$key] ?? 'Mensagem',
            'direction' => $this->direction($message),
        ];
    }

    private function direction(CommunicationMessage $message): string
    {
        if ($message->direction instanceof MessageDirection) {
            return $message->direction->value;
        }

        return (string) $message->direction;
    }
}
