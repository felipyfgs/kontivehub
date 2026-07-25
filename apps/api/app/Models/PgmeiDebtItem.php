<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'office_id',
    'client_id',
    'observation_id',
    'position',
    'logical_key',
    'periodo_apuracao',
    'tributo',
    'amount_cents',
    'ente_federado',
    'situacao_debito',
])]
class PgmeiDebtItem extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'amount_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Itens de observação PGMEI são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Itens PGMEI não podem ser removidos diretamente.');
        });
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(PgmeiDebtObservation::class, 'observation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'position' => (int) $this->position,
            'periodo_apuracao' => $this->periodo_apuracao,
            'period_key' => substr((string) $this->periodo_apuracao, 0, 4)
                .'-'.substr((string) $this->periodo_apuracao, 4, 2),
            'tributo' => $this->tributo,
            'tribute' => $this->tributo,
            'amount_cents' => (int) $this->amount_cents,
            'ente_federado' => $this->ente_federado,
            'federated_entity' => $this->ente_federado,
            'situacao_debito' => $this->situacao_debito,
            'situacao_original' => $this->situacao_debito,
            'original_status' => $this->situacao_debito,
        ];
    }
}
