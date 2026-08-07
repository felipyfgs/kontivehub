<?php

namespace App\DTO\Communication;

use App\Enums\Communication\MessageKind;

final readonly class MessageCreationData
{
    /**
     * @param  array<string, mixed>  $richPayload
     */
    public function __construct(
        public string $body,
        public bool $internalNote,
        public ?MessageKind $requestedKind,
        public bool $ptt,
        public bool $gif,
        public array $richPayload,
        public ?int $replyToMessageId,
        public ?string $idempotencyKey,
        public ?MessageUploadData $upload,
        public ?int $receiptMessageId = null,
        public bool $outboundInitiation = false,
        public bool $ptv = false,
        public bool $viewOnce = false,
        public ?string $libraryStickerId = null,
        public ?string $libraryStickerTempPath = null,
    ) {}
}
