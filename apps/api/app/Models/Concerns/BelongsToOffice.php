<?php

namespace App\Models\Concerns;

use App\Models\Office;
use App\Support\CurrentOffice;
use App\Support\FiscalDataModel\PrivilegedOfficeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToOffice
{
    public static function bootBelongsToOffice(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('office_id') === null) {
                $officeId = app(CurrentOffice::class)->id();
                if ($officeId !== null) {
                    $model->setAttribute('office_id', $officeId);
                }
            }
        });

        static::addGlobalScope('office', function (Builder $builder): void {
            $officeId = app(CurrentOffice::class)->id();

            if ($officeId !== null) {
                $builder->where($builder->getModel()->getTable().'.office_id', $officeId);

                return;
            }

            // Contexto privilegiado tipado (jobs/console/plataforma): sem filtro.
            if (PrivilegedOfficeContext::isOpen()) {
                return;
            }

            // Fail-closed: sem escritório ativo não vaza linhas de todos os tenants.
            // Flag permite compatibilidade temporária da suíte legada (phpunit).
            if (self::failClosedScopesEnabled()) {
                $builder->whereRaw('0 = 1');
            }
        });
    }

    protected static function failClosedScopesEnabled(): bool
    {
        return (bool) config('fiscal_data_model.fail_closed_scopes', true);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
