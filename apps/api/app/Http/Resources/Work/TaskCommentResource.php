<?php

namespace App\Http\Resources\Work;

use App\Models\WorkComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkComment */
final class TaskCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkComment $comment */
        $comment = $this->resource;
        $data = CommentResource::make($comment)->resolve($request);
        $data['author_membership_id'] = $comment->author_membership_id;

        return $data;
    }
}
