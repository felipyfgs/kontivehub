<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['observation_id', 'office_id', 'client_id', 'document_digest', 'document_masked', 'document_type', 'revenue_code', 'revenue_description', 'paid_on', 'due_on', 'total_amount', 'created_at'])]
class PagtowebPaymentListItem extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'due_on' => 'date', 'total_amount' => 'decimal:2', 'created_at' => 'immutable_datetime'];
    }

    /** @return array<string,mixed> */
    public function toPublicArray(): array
    {
        return ['document_masked' => $this->document_masked, 'document_type' => $this->document_type, 'revenue_code' => $this->revenue_code, 'revenue_description' => $this->revenue_description, 'paid_on' => $this->paid_on?->toDateString(), 'due_on' => $this->due_on?->toDateString(), 'total_amount' => $this->total_amount];
    }
}
