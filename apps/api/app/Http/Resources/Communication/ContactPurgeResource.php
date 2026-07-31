<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\ContactPurgeResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContactPurgeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContactPurgeResult $result */
        $result = $this->resource;

        return [
            'contact_id' => $result->contactId,
            'purged_at' => $result->purgedAt,
            'deleted_blobs' => $result->deletedBlobs,
            'tombstone_digest' => $result->tombstoneDigest,
        ];
    }
}
