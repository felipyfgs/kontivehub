<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['office_id', 'client_id', 'payment_count', 'filter_summary', 'last_valid_query_at', 'last_observation_id', 'last_run_id', 'source_provenance'])]
class PagtowebPaymentCountProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['payment_count' => 'integer', 'filter_summary' => 'array', 'last_valid_query_at' => 'immutable_datetime'];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return ['payment_count' => $this->payment_count, 'filter_summary' => $this->filter_summary, 'observed_at' => $this->last_valid_query_at?->toIso8601String(), 'source_provenance' => $this->source_provenance];
    }
}
