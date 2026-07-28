<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationContactPurgeResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationContactPurgeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationContactPurgeResult $result */
        $result = $this->resource;

        return [
            'contact_id' => $result->contactId,
            'purged_at' => $result->purgedAt,
            'deleted_blobs' => $result->deletedBlobs,
            'tombstone_digest' => $result->tombstoneDigest,
        ];
    }
}
