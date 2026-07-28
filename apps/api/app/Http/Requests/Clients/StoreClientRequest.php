<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCreationData;
use App\Enums\RegistrationStatus;
use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Rules\ValidCnpj;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreClientRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => ['tenant_id não é aceito; use o Tenant corrente.'],
            ]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientPolicy::class)->create($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['required', 'string', new ValidCnpj],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'inactive_reason' => ['nullable', 'string', 'max:1000'],
            'legal_nature_code' => ['nullable', 'string', 'max:16'],
            'legal_nature_name' => ['nullable', 'string', 'max:255'],
            'company_size_code' => ['nullable', 'string', 'max:16'],
            'company_size_name' => ['nullable', 'string', 'max:255'],
            'capital_social' => ['nullable', 'numeric', 'min:0'],
            'responsible_qualification_code' => ['nullable', 'string', 'max:16'],
            'responsible_qualification_name' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['nullable', 'string', Rule::in(TaxRegimeCode::currentProjectionValues())],
            // primeiro estabelecimento
            'trade_name' => ['nullable', 'string', 'max:255'],
            'is_headquarters' => ['sometimes', 'boolean'],
            'establishment_is_active' => ['sometimes', 'boolean'],
            'registration_status' => ['nullable', 'string', Rule::enum(RegistrationStatus::class)],
            'registration_status_at' => ['nullable', 'date'],
            'registration_status_reason' => ['nullable', 'string', 'max:255'],
            'special_situation' => ['nullable', 'string', 'max:255'],
            'special_situation_at' => ['nullable', 'date'],
            'activity_started_at' => ['nullable', 'date'],
            'main_cnae_code' => ['nullable', 'string', 'max:16'],
            'main_cnae_name' => ['nullable', 'string', 'max:255'],
            'secondary_cnaes' => ['nullable', 'array', 'max:100'],
            'secondary_cnaes.*.code' => ['required_with:secondary_cnaes', 'string', 'max:16'],
            'secondary_cnaes.*.name' => ['nullable', 'string', 'max:255'],
            'state_registrations' => ['nullable', 'array', 'max:50'],
            'state_registrations.*.number' => ['required_with:state_registrations', 'string', 'max:32'],
            'state_registrations.*.state' => ['nullable', 'string', 'size:2'],
            'state_registrations.*.active' => ['nullable', 'boolean'],
            'shareholders' => ['nullable', 'array', 'max:200'],
            'shareholders.*.name' => ['required_with:shareholders', 'string', 'max:255'],
            'shareholders.*.type' => ['nullable', 'string', 'max:64'],
            'shareholders.*.qualification_code' => ['nullable', 'string', 'max:16'],
            'shareholders.*.qualification_name' => ['nullable', 'string', 'max:255'],
            'shareholders.*.entered_at' => ['nullable', 'date'],
            'shareholders.*.document_masked' => ['nullable', 'string', 'max:32'],
            'public_email' => ['nullable', 'email', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:32'],
            'public_phone_secondary' => ['nullable', 'string', 'max:32'],
            'public_fax' => ['nullable', 'string', 'max:32'],
            'simples_optant' => ['nullable', 'boolean'],
            'mei_optant' => ['nullable', 'boolean'],
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
            // contato interno responsável opcional (distinto do contato público do CNPJ)
            'initial_contact' => ['nullable', 'array'],
            'initial_contact.name' => ['required_with:initial_contact', 'string', 'max:255'],
            'initial_contact.role' => ['nullable', 'string', 'max:255'],
            'initial_contact.email' => ['nullable', 'email', 'max:255'],
            'initial_contact.phone' => ['nullable', 'string', 'max:32'],
            'initial_contact.is_whatsapp' => ['sometimes', 'boolean'],
            'initial_contact.is_primary' => ['sometimes', 'boolean'],
            'initial_contact.receives_alerts' => ['sometimes', 'boolean'],
            'initial_contact.notes' => ['nullable', 'string'],
            'initial_contact.tenant_id' => ['prohibited'],
            'initial_contact.client_id' => ['prohibited'],
            'custom_fields' => ['nullable', 'array', 'max:20'],
            'custom_fields.*.label' => ['required', 'string', 'max:100'],
            'custom_fields.*.type' => ['required', 'string', Rule::in(['TEXT', 'SECRET'])],
            'custom_fields.*.value' => ['nullable', 'string', 'max:10000'],
            'custom_fields.*.tenant_id' => ['prohibited'],
            'custom_fields.*.client_id' => ['prohibited'],
            // tenant_id / proveniência do cliente nunca são autoridade do navegador
            // (campos ausentes em validated() → ignorados; proibidos se enviados como autoridade)
            'tenant_id' => ['prohibited'],
            'root_cnpj' => ['prohibited'],
            'registration_source' => ['prohibited'],
            'source' => ['prohibited'],
            'source_updated_at' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $contact = $this->input('initial_contact');

            if (is_array($contact)
                && $contact !== []
                && blank($contact['email'] ?? null)
                && blank($contact['phone'] ?? null)) {
                $validator->errors()->add(
                    'initial_contact.email',
                    'Informe ao menos um canal do contato responsável: e-mail ou telefone.'
                );
            }

            $hasSecret = collect($this->input('custom_fields', []))
                ->contains(fn ($field): bool => is_array($field) && ($field['type'] ?? null) === 'SECRET');

            if ($hasSecret && app(CurrentTenant::class)->role() !== TenantRole::TenantAdmin) {
                $validator->errors()->add(
                    'custom_fields',
                    'Somente administradores podem cadastrar campos secretos.'
                );
            }
        });
    }

    public function toDto(): ClientCreationData
    {
        return new ClientCreationData($this->validated());
    }
}
