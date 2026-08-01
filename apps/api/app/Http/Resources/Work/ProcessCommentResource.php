<?php

namespace App\Http\Resources\Work;

use App\Models\WorkComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkComment */
final class ProcessCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkComment $comment */
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author_membership_id' => $comment->author_membership_id,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
