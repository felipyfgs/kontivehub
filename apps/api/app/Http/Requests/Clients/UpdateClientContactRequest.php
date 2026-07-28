<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientContactUpdateData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Policies\ClientContactPolicy;
use App\Policies\ClientPolicy;

final class UpdateClientContactRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');
        $contact = $this->route('contact');

        return $actor instanceof User
            && $client instanceof Client
            && $contact instanceof ClientContact
            && app(ClientPolicy::class)->update($actor, $client)
            && app(ClientContactPolicy::class)->update($actor, $contact);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_whatsapp' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_alerts' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
            'client_id' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $contact = $this->route('contact');
            if (! $contact instanceof ClientContact) {
                return;
            }

            $willBePrimary = $this->has('is_primary')
                ? $this->boolean('is_primary')
                : (bool) $contact->is_primary;
            $willBeActive = $this->has('is_active')
                ? $this->boolean('is_active')
                : (bool) $contact->is_active;

            if ($willBePrimary && ! $willBeActive) {
                $validator->errors()->add('is_primary', 'Contato principal precisa estar ativo.');
                $validator->errors()->add(
                    'is_active',
                    'Não é possível marcar como principal um contato inativo.',
                );
            }
        });
    }

    public function toDto(): ClientContactUpdateData
    {
        return new ClientContactUpdateData($this->validated());
    }
}
