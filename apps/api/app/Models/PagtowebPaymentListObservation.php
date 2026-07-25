<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['office_id', 'client_id', 'filter_summary', 'returned_count', 'digest', 'observed_at', 'source_run_id', 'source_provenance', 'created_at'])]
class PagtowebPaymentListObservation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['filter_summary' => 'array', 'returned_count' => 'integer', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Observações PAGTOWEB são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Observações PAGTOWEB não podem ser removidas diretamente.');
        });
    }

    /** @return array<string,mixed> */
    public function toPublicArray(): array
    {
        return ['filter_summary' => $this->filter_summary, 'returned_count' => $this->returned_count, 'observed_at' => $this->observed_at?->toIso8601String(), 'source_provenance' => $this->source_provenance];
    }
}
