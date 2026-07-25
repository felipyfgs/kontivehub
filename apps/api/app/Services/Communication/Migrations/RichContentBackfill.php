<?php

namespace App\Services\Communication\Migrations;

use App\DTO\Communication\MessageSemanticContent;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RichContentBackfill
{
    /**
     * @return array{scanned:int,migrated:int,conflicts:int,last_id:int}
     */
    public function run(int $officeId, int $afterId = 0, int $chunk = 200, ?int $maximum = null): array
    {
        $result = ['scanned' => 0, 'migrated' => 0, 'conflicts' => 0, 'last_id' => $afterId];
        $remaining = $maximum;
        $query = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('id', '>', $afterId)
            ->orderBy('id');

        $query->chunkById(max(1, min(1000, $chunk)), function ($messages) use (&$result, &$remaining): bool {
            foreach ($messages as $message) {
                if ($remaining !== null && $remaining <= 0) {
                    return false;
                }
                $remaining = $remaining === null ? null : $remaining - 1;
                $result['scanned']++;
                $result['last_id'] = (int) $message->id;
                $outcome = $this->migrateOne((int) $message->office_id, (int) $message->id);
                $result[$outcome]++;
            }

            return $remaining === null || $remaining > 0;
        }, 'id');

        return $result;
    }

    /** @return 'migrated'|'conflicts' */
    private function migrateOne(int $officeId, int $messageId): string
    {
        return DB::transaction(function () use ($officeId, $messageId): string {
            $message = CommunicationMessage::query()->withoutGlobalScopes()
                ->where('office_id', $officeId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $legacy = [];
            foreach (MessageSemanticContent::KEYS as $key) {
                if (array_key_exists($key, $metadata)) {
                    $legacy[$key] = $metadata[$key];
                }
            }
            if (is_array($metadata['contact'] ?? null)) {
                $legacy['contacts'] = [$metadata['contact']];
            }
            if (is_array($metadata['interactive_response'] ?? null)) {
                $response = $metadata['interactive_response'];
                $legacy['interactive'] = array_filter([
                    'mode' => 'LEGACY_RESPONSE',
                    'selected_id' => is_string($response['selected_id'] ?? null) ? $response['selected_id'] : null,
                    'display_text' => is_string($response['text'] ?? null) ? $response['text'] : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
            foreach (['reactions', 'poll_votes'] as $actionKey) {
                if (is_array($metadata[$actionKey] ?? null)) {
                    $legacy[$actionKey] = $metadata[$actionKey];
                }
            }
            if ($legacy === []) {
                if ($message->provider_type === null) {
                    $message->forceFill(['provider_type' => $this->legacyProviderType($message->kind)])->saveQuietly();
                }

                return 'migrated';
            }
            $current = is_array($message->content_encrypted) ? $message->content_encrypted : [];
            if ($current !== [] && $current !== $legacy) {
                Log::warning('communication.rich_content_backfill_conflict', [
                    'office_id' => $officeId,
                    'message_id' => $messageId,
                ]);

                return 'conflicts';
            }

            MessageSemanticContent::assertShape($legacy, $message->kind);
            $message->forceFill([
                'provider_type' => $message->provider_type ?: $this->legacyProviderType($message->kind),
                'content_encrypted' => $legacy,
            ])->saveQuietly();
            $verified = $message->fresh();
            if (! is_array($verified?->content_encrypted) || $verified->content_encrypted !== $legacy) {
                throw new \RuntimeException('Falha ao verificar conteúdo rico cifrado.');
            }
            foreach (array_unique([
                ...array_keys($legacy), 'contact', 'interactive_response', 'reactions', 'poll_votes',
            ]) as $key) {
                unset($metadata[$key]);
            }
            $verified->forceFill(['metadata' => $metadata])->saveQuietly();

            return 'migrated';
        });
    }

    private function legacyProviderType(MessageKind $kind): string
    {
        return match ($kind) {
            MessageKind::Text => 'legacyText',
            MessageKind::Image => 'legacyImage',
            MessageKind::Audio => 'legacyAudio',
            MessageKind::Video => 'legacyVideo',
            MessageKind::Document => 'legacyDocument',
            MessageKind::Sticker => 'legacySticker',
            MessageKind::Location => 'legacyLocation',
            MessageKind::Contact => 'legacyContact',
            MessageKind::Poll => 'legacyPoll',
            MessageKind::Interactive => 'legacyInteractive',
            MessageKind::Unsupported => 'legacyUnsupported',
            MessageKind::Note => 'internalNote',
        };
    }
}
