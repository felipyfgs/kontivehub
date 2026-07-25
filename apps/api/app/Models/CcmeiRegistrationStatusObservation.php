<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['office_id', 'client_id', 'status', 'enquadrado_mei', 'situation', 'count', 'digest', 'observed_at', 'source_run_id', 'source_provenance', 'created_at'])]
class CcmeiRegistrationStatusObservation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['enquadrado_mei' => 'boolean', 'count' => 'integer', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Observações cadastrais CCMEI são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Observações cadastrais CCMEI não podem ser removidas diretamente.');
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'source_run_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'enquadrado_mei' => $this->enquadrado_mei,
            'situation' => $this->situation, 'count' => $this->count,
            'observed_at' => $this->observed_at?->toIso8601String(), 'source_provenance' => $this->source_provenance];
    }
}
