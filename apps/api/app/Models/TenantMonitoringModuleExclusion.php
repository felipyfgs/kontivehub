<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'client_id',
    'module_key',
    'submodule',
    'excluded_by',
])]
class TenantMonitoringModuleExclusion extends Model
{
    use BelongsToTenant;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function excludedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'client_id' => $this->client_id,
            'module_key' => $this->module_key,
            'submodule' => $this->submodule,
            'excluded_by' => $this->excluded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
