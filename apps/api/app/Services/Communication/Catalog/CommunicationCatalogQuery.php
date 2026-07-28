<?php

namespace App\Services\Communication\Catalog;

use App\Models\CommunicationLabel;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

final readonly class CommunicationCatalogQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    /** @return Collection<int, CommunicationLabel> */
    public function labels(): Collection
    {
        return CommunicationLabel::query()->orderBy('name')->get();
    }

    /** @return array<string, mixed> */
    public function outboundCapabilities(): array
    {
        $enabled = (bool) config('communication.enabled')
            && (bool) config('communication.gateway.enabled')
            && (bool) $this->currentTenant->tenant()->communication_enabled;

        return [
            'enabled' => $enabled,
            'requires_permission' => 'communication.reply',
            'kinds' => [
                'TEXT' => ['supported' => true, 'max_text_bytes' => 4096, 'link_preview' => true],
                'IMAGE' => ['supported' => true, 'mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
                'AUDIO' => ['supported' => true, 'ptt' => true, 'mime_types' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/webm']],
                'VIDEO' => ['supported' => true, 'gif' => false, 'mime_types' => ['video/mp4', 'video/webm']],
                'DOCUMENT' => ['supported' => true, 'mime_types' => ['application/pdf', 'text/plain', 'application/zip']],
                'STICKER' => ['supported' => true, 'mime_types' => ['image/webp']],
                'LOCATION' => ['supported' => true],
                'CONTACT' => ['supported' => true, 'multiple' => false],
                'POLL' => ['supported' => true, 'max_options' => 12],
                'INTERACTIVE' => ['supported' => true, 'modes' => ['BUTTONS', 'LIST'], 'max_options' => 20],
                'UNSUPPORTED' => ['supported' => false, 'error_code' => 'MESSAGE_KIND_UNSUPPORTED'],
            ],
            'max_media_bytes' => (int) config('communication.media.max_bytes', 20_971_520),
        ];
    }
}
