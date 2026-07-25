<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OfficeInstitutionalProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil institucional único do escritório (CNPJ, razão social, e-mail, telefone).
 * Escopo sempre via CurrentOffice — nunca office_id do client HTTP.
 */
#[Fillable([
    'office_id',
    'cnpj',
    'legal_name',
    'institutional_email',
    'institutional_phone',
])]
class OfficeInstitutionalProfile extends Model
{
    /** @use HasFactory<OfficeInstitutionalProfileFactory> */
    use BelongsToOffice;

    use HasFactory;

    protected static function newFactory(): OfficeInstitutionalProfileFactory
    {
        return OfficeInstitutionalProfileFactory::new();
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function isComplete(): bool
    {
        return $this->cnpj !== null && $this->cnpj !== ''
            && $this->legal_name !== null && trim($this->legal_name) !== ''
            && $this->institutional_email !== null && trim($this->institutional_email) !== ''
            && $this->institutional_phone !== null && trim($this->institutional_phone) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'legal_name' => $this->legal_name,
            'institutional_email' => $this->institutional_email,
            'institutional_phone' => $this->institutional_phone,
            'is_complete' => $this->isComplete(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
