<?php

namespace App\Models;

use App\Enums\OfficeAutXmlEnrollmentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'office_fiscal_identity_id', 'establishment_id', 'status',
    'activated_at', 'first_seen_at', 'last_seen_at', 'confirmed_by', 'notes',
])]
class OfficeAutXmlEnrollment extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected $table = 'office_autxml_enrollments';

    protected function casts(): array
    {
        return [
            'status' => OfficeAutXmlEnrollmentStatus::class,
            'activated_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function fiscalIdentity(): BelongsTo
    {
        return $this->belongsTo(OfficeFiscalIdentity::class, 'office_fiscal_identity_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_fiscal_identity_id' => $this->office_fiscal_identity_id,
            'establishment_id' => $this->establishment_id,
            'status' => $this->status->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
