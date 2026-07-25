<?php

namespace App\Http\Requests\Clients;

use App\Enums\RegistrationStatus;
use App\Rules\ValidCnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', new ValidCnpj],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'is_matrix' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'registration_status' => ['nullable', 'string', Rule::enum(RegistrationStatus::class)],
            'registration_status_at' => ['nullable', 'date'],
            'registration_status_reason' => ['nullable', 'string', 'max:255'],
            'activity_started_at' => ['nullable', 'date'],
            'main_cnae_code' => ['nullable', 'string', 'max:16'],
            'main_cnae_name' => ['nullable', 'string', 'max:255'],
            'public_email' => ['nullable', 'email', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:32'],
            'capture_enabled' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'array'],
            'address.postal_code' => ['nullable', 'string', 'max:16'],
            'address.street_type' => ['nullable', 'string', 'max:32'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:32'],
            'address.complement' => ['nullable', 'string', 'max:255'],
            'address.district' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.city_ibge_code' => ['nullable', 'string', 'max:16'],
            'address.state' => ['nullable', 'string', 'size:2'],
            'address.country' => ['nullable', 'string', 'max:64'],
            'office_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'registration_source' => ['prohibited'],
        ];
    }
}
