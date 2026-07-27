<?php

namespace App\Models;

use App\Enums\PgdasdOperationKind;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'client_id',
    'projection_id',
    'kind',
    'period_key',
    'logical_key',
    'raw_operation_type',
    'normalized_operation_type',
    'declaration_number',
    'das_number',
    'transmitted_at',
    'issued_at',
    'malha',
    'payment_located',
    'payment_observed_at',
    'pagtoweb_payment_status',
    'pagtoweb_verified_at',
    'pagtoweb_paid_at',
    'pagtoweb_amount_cents',
    'pagtoweb_source_run_id',
    'pagtoweb_source_item_id',
    'amount_cents',
    'amount_source',
    'amount_parser_version',
    'amount_resolved_at',
    'amount_source_artifact_id',
    'first_seen_at',
    'last_seen_at',
    'source_run_id',
    'metadata',
])]
class PgdasdOperation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'kind' => PgdasdOperationKind::class,
            'transmitted_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'payment_located' => 'boolean',
            'payment_observed_at' => 'immutable_datetime',
            'pagtoweb_verified_at' => 'immutable_datetime',
            'pagtoweb_paid_at' => 'immutable_date',
            'pagtoweb_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'amount_resolved_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(TaxObligationProjection::class, 'projection_id');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'source_run_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(PgdasdArtifact::class, 'operation_id');
    }

    public function operationKind(): ?PgdasdOperationKind
    {
        return $this->kind instanceof PgdasdOperationKind
            ? $this->kind
            : PgdasdOperationKind::tryFrom((string) $this->kind);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'period_key' => $this->period_key,
            'kind' => $this->operationKind()?->value,
            'normalized_operation_type' => $this->normalized_operation_type,
            'raw_operation_type' => $this->raw_operation_type,
            'declaration_number' => $this->declaration_number,
            'das_number' => $this->das_number,
            'transmitted_at' => $this->transmitted_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'payment_located' => $this->payment_located,
            'payment_observation' => $this->payment_located === false
                ? 'Pagamento não localizado até a consulta.'
                : ($this->payment_located === true ? 'Pagamento localizado até a consulta.' : null),
            'malha' => $this->malha,
            'observed_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
