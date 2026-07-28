<?php

namespace App\Models;

use App\Enums\TenantAutXmlEnrollmentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'tenant_fiscal_identity_id', 'establishment_id', 'status',
    'activated_at', 'first_seen_at', 'last_seen_at', 'confirmed_by', 'notes',
])]
class TenantAutXmlEnrollment extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'tenant_autxml_enrollments';

    protected function casts(): array
    {
        return [
            'status' => TenantAutXmlEnrollmentStatus::class,
            'activated_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function fiscalIdentity(): BelongsTo
    {
        return $this->belongsTo(TenantFiscalIdentity::class, 'tenant_fiscal_identity_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
