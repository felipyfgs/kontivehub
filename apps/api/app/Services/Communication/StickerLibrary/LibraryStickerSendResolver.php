<?php

namespace App\Services\Communication\StickerLibrary;

use App\DTO\Communication\MessageUploadData;
use App\Enums\Communication\StickerAvailability;
use App\Models\CommunicationConversation;
use App\Models\CommunicationStickerObservation;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LibraryStickerSendResolver
{
    public function __construct(private MediaStore $media) {}

    /**
     * Resolve authorized private WebP bytes into a temporary upload for the existing STICKER path.
     *
     * @return array{upload: MessageUploadData, observation: CommunicationStickerObservation, temp_path: string}
     */
    public function resolve(CommunicationConversation $conversation, string $publicId): array
    {
        $conversation->loadMissing('inbox');
        $inbox = $conversation->inbox;
        if ($inbox === null) {
            throw ValidationException::withMessages([
                'library_sticker_id' => 'Caixa de entrada indisponível para envio da figurinha.',
            ]);
        }

        $observation = CommunicationStickerObservation::query()
            ->where('inbox_id', $inbox->id)
            ->where('public_id', $publicId)
            ->visible()
            ->with('content')
            ->first();
        if ($observation === null) {
            throw ValidationException::withMessages([
                'library_sticker_id' => 'Figurinha não encontrada nesta caixa de entrada.',
            ]);
        }
        if ($observation->availability !== StickerAvailability::Available || $observation->content === null) {
            throw ValidationException::withMessages([
                'library_sticker_id' => 'Esta figurinha não está disponível para envio.',
            ]);
        }

        $content = $observation->content;
        try {
            $bytes = $this->media->readValidated(
                (string) $content->object_id_encrypted,
                (array) $content->storage_context_encrypted,
                (int) $content->size_bytes,
                (string) $content->sha256,
            );
        } catch (RuntimeException) {
            $observation->forceFill([
                'availability' => StickerAvailability::Unreadable,
                'unavailable_reason' => 'PRIVATE_OBJECT_UNREADABLE',
            ])->save();

            throw ValidationException::withMessages([
                'library_sticker_id' => 'A figurinha privada está temporariamente indisponível.',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'library-sticker-');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw ValidationException::withMessages([
                'library_sticker_id' => 'Não foi possível preparar a figurinha para envio.',
            ]);
        }

        return [
            'upload' => new MessageUploadData(
                path: $tmp,
                originalName: 'sticker-'.$observation->public_id.'.webp',
                detectedMime: 'image/webp',
                clientMime: 'image/webp',
            ),
            'observation' => $observation,
            'temp_path' => $tmp,
        ];
    }
}
