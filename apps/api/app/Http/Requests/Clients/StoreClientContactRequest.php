<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientContactCreationData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientContactPolicy;
use App\Policies\ClientPolicy;

final class StoreClientContactRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(ClientPolicy::class)->update($actor, $client)
            && app(ClientContactPolicy::class)->create($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
            $email = $this->input('email');
            $phone = $this->input('phone');
            if (blank($email) && blank($phone)) {
                $validator->errors()->add('email', 'Informe ao menos um canal: e-mail ou telefone.');
            }

            $isPrimary = $this->boolean('is_primary');
            $isActive = $this->has('is_active') ? $this->boolean('is_active') : true;
            if ($isPrimary && ! $isActive) {
                $validator->errors()->add('is_primary', 'Contato principal precisa estar ativo.');
                $validator->errors()->add(
                    'is_active',
                    'Não é possível marcar como principal um contato inativo.',
                );
            }
        });
    }

    public function toDto(): ClientContactCreationData
    {
        return new ClientContactCreationData($this->validated());
    }
}
