<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationSharedContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $attachment = ($item['type'] ?? null) === 'attachment'
            ? [
                'id' => (int) $item['attachment_id'],
                'filename' => (string) $item['filename'],
                'mime_type' => (string) $item['mime_type'],
                'size_bytes' => (int) $item['size_bytes'],
                'preview_url' => $item['preview_url'] ?? null,
                'download_url' => $item['download_url'] ?? null,
            ]
            : null;
        $link = ($item['type'] ?? null) === 'link'
            ? [
                'url' => (string) $item['url'],
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? null,
            ]
            : null;

        return [
            'id' => (string) $item['id'],
            'type' => (string) $item['type'],
            'category' => (string) $item['category'],
            'conversation_id' => (int) $item['conversation_id'],
            'message_id' => (int) $item['message_id'],
            'occurred_at' => $item['occurred_at'] ?? null,
            'attachment' => $attachment,
            'link' => $link,
        ];
    }
}
