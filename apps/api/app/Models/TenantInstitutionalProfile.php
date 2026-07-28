<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantInstitutionalProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil institucional único do escritório (CNPJ, razão social, e-mail, telefone).
 * Escopo sempre via CurrentTenant — nunca tenant_id do client HTTP.
 */
#[Fillable([
    'tenant_id',
    'cnpj',
    'legal_name',
    'institutional_email',
    'institutional_phone',
])]
class TenantInstitutionalProfile extends Model
{
    /** @use HasFactory<TenantInstitutionalProfileFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected static function newFactory(): TenantInstitutionalProfileFactory
    {
        return TenantInstitutionalProfileFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isComplete(): bool
    {
        return $this->cnpj !== null && $this->cnpj !== ''
            && $this->legal_name !== null && trim($this->legal_name) !== ''
            && $this->institutional_email !== null && trim($this->institutional_email) !== ''
            && $this->institutional_phone !== null && trim($this->institutional_phone) !== '';
    }
}
