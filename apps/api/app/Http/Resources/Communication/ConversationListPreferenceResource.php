<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array{status: string, sort_by: string, is_default: bool} */
final class ConversationListPreferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{status: string, sort_by: string, is_default?: bool} $data */
        $data = is_array($this->resource) ? $this->resource : [
            'status' => (string) $this->resource->status,
            'sort_by' => (string) $this->resource->sort_by,
            'is_default' => false,
        ];

        return [
            'status' => $data['status'],
            'sort_by' => $data['sort_by'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }
}
