<?php

namespace App\Http\Resources\FgtsEsocial;

use App\Models\EsocialEventEvidence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialEventPageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @var array<string, mixed>
     */
    private array $coverage = [];

    /** @param array<string, mixed> $coverage */
    public function withCoverage(array $coverage): self
    {
        $this->coverage = $coverage;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, EsocialEventEvidence> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = FgtsEsocialEventResource::collection(
            $page->getCollection(),
        )->resolve($request);
        $payload['coverage'] = [
            'partial' => true,
            'limitations' => $this->coverage['limitations'] ?? [],
            'declares_fgts_digital_debt' => false,
        ];

        return $payload;
    }
}
