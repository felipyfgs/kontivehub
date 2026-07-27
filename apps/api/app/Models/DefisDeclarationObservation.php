<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'tenant_id',
    'client_id',
    'calendar_year',
    'declaration_type',
    'digest',
    'observed_at',
    'source_run_id',
    'source_provenance',
])]
class DefisDeclarationObservation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Observações DEFIS são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Observações DEFIS não podem ser removidas diretamente.');
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
}
