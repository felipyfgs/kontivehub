<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalMutationOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalMutationOperation */
final class FiscalMutationOperationResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalMutationOperation $operation */
        $operation = $this->resource;

        return $operation->toPublicArray();
    }
}
