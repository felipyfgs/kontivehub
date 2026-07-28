<?php

namespace App\Http\Resources\Fiscal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array<string, mixed> $resource
 */
final class DeclarationOperationPayloadResource extends JsonResource
{
    public static $wrap = 'data';

    private ?int $status = null;

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }

    public function withResponse($request, $response): void
    {
        if ($this->status !== null) {
            $response->setStatusCode($this->status);
        }
    }
}
