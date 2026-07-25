<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_id', 'client_id', 'last_observation_id', 'last_run_id', 'last_valid_query_at', 'source_provenance'])]
class PagtowebPaymentListProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['last_valid_query_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PagtowebPaymentListObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(PagtowebPaymentListObservation::class, 'last_observation_id');
    }
}
