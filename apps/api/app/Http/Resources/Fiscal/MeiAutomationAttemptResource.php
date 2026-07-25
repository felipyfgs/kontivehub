<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MeiAutomationAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeiAutomationAttempt */
final class MeiAutomationAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MeiAutomationAttempt $attempt */
        $attempt = $this->resource;

        return $attempt->toPublicArray();
    }
}
