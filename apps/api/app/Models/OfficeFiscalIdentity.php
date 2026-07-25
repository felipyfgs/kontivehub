<?php

namespace App\Models;

use App\Enums\OfficeFiscalIdentityStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'cnpj', 'root_cnpj', 'status', 'legal_name',
    'activated_at', 'deactivated_at',
])]
class OfficeFiscalIdentity extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OfficeFiscalIdentityStatus::class,
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(OfficeCredential::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OfficeAutXmlEnrollment::class);
    }

    public function distributionCursors(): HasMany
    {
        return $this->hasMany(OfficeDistributionCursor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'root_cnpj' => $this->root_cnpj,
            'status' => $this->status->value,
            'legal_name' => $this->legal_name,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
        ];
    }
}
