<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'client_id',
    'cnpj',
    'trade_name',
    'is_headquarters',
    'is_active',
    'registration_status',
    'registration_status_at',
    'registration_status_reason',
    'activity_started_at',
    'main_cnae_code',
    'main_cnae_name',
    'secondary_cnaes',
    'state_registrations',
    'shareholders',
    'address_postal_code',
    'address_street_type',
    'address_street',
    'address_number',
    'address_complement',
    'address_district',
    'address_city',
    'address_city_ibge_code',
    'address_state',
    'address_country',
    'public_email',
    'public_phone',
    'public_phone_secondary',
    'public_fax',
    'special_situation',
    'special_situation_at',
    'simples_optant',
    'mei_optant',
    'capture_enabled',
    'registration_source',
    'registration_refreshed_at',
])]
class Establishment extends Model
{
    /** @use HasFactory<EstablishmentFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'is_active' => 'boolean',
            'capture_enabled' => 'boolean',
            'simples_optant' => 'boolean',
            'mei_optant' => 'boolean',
            'secondary_cnaes' => 'array',
            'state_registrations' => 'array',
            'shareholders' => 'array',
            'registration_status' => RegistrationStatus::class,
            'registration_status_at' => 'date',
            'special_situation_at' => 'date',
            'activity_started_at' => 'date',
            'registration_source' => RegistrationSource::class,
            'registration_refreshed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function syncCursors(): HasMany
    {
        return $this->hasMany(SyncCursor::class);
    }

    /**
     * @return array{
     *   postal_code: ?string,
     *   street_type: ?string,
     *   street: ?string,
     *   number: ?string,
     *   complement: ?string,
     *   district: ?string,
     *   city: ?string,
     *   city_ibge_code: ?string,
     *   state: ?string,
     *   country: ?string
     * }
     */
    public function addressPayload(): array
    {
        return [
            'postal_code' => $this->address_postal_code,
            'street_type' => $this->address_street_type,
            'street' => $this->address_street,
            'number' => $this->address_number,
            'complement' => $this->address_complement,
            'district' => $this->address_district,
            'city' => $this->address_city,
            'city_ibge_code' => $this->address_city_ibge_code,
            'state' => $this->address_state,
            'country' => $this->address_country,
        ];
    }
}
