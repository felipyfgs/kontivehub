<?php

namespace App\Http\Resources\Fiscal;

use App\Models\DctfwebDeclaration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array{
 *     declaration: DctfwebDeclaration,
 *     evidence_versions: list<array<string, mixed>>
 * }
 */
final class DctfwebDeclarationDetailResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     declaration: DctfwebDeclaration,
         *     evidence_versions: list<array<string, mixed>>
         * } $detail
         */
        $detail = $this->resource;

        return [
            'data' => (new DctfwebDeclarationResource(
                $detail['declaration'],
            ))->resolve($request),
            'evidence_versions' => $detail['evidence_versions'],
        ];
    }
}
