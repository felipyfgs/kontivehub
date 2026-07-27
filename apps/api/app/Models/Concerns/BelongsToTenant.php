<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\FiscalDataModel\PrivilegedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $tenantId = app(CurrentTenant::class)->id();
                if ($tenantId !== null) {
                    $model->setAttribute('tenant_id', $tenantId);
                }
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(CurrentTenant::class)->id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);

                return;
            }

            // Contexto privilegiado tipado (jobs/console/plataforma): sem filtro.
            if (PrivilegedTenantContext::isOpen()) {
                return;
            }

            // Fail-closed: sem tenant ativo não vaza linhas de todos os tenants.
            $builder->whereRaw('0 = 1');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
