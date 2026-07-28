<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MitAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin array{
 *     assessments: Collection<int, MitAssessment>
 * }
 */
final class MitLocalAssessmentListResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     assessments: Collection<int, MitAssessment>
         * } $payload
         */
        $payload = $this->resource;

        return [
            'data' => MitAssessmentResource::collection(
                $payload['assessments'],
            )->resolve($request),
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ];
    }
}
