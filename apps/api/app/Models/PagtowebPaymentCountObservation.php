<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['office_id', 'client_id', 'payment_count', 'filter_summary', 'digest', 'observed_at', 'source_run_id', 'source_provenance', 'created_at'])]
class PagtowebPaymentCountObservation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payment_count' => 'integer', 'filter_summary' => 'array', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Observações de contagem PAGTOWEB são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Observações de contagem PAGTOWEB não podem ser removidas diretamente.');
        });
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return ['payment_count' => $this->payment_count, 'filter_summary' => $this->filter_summary, 'observed_at' => $this->observed_at?->toIso8601String(), 'source_provenance' => $this->source_provenance];
    }
}
