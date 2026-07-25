<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'module_key',
    'submodule',
    'excluded_by',
])]
class OfficeMonitoringModuleExclusion extends Model
{
    use BelongsToOffice;

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
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'module_key' => $this->module_key,
            'submodule' => $this->submodule,
            'excluded_by' => $this->excluded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
