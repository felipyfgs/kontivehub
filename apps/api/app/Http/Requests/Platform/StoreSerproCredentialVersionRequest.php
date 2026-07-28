<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\CredentialVersionRegistrationData;
use App\Enums\SerproEnvironment;
use App\Exceptions\SerproConfigurationException;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final class StoreSerproCredentialVersionRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', Rule::enum(SerproEnvironment::class)],
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consumer_key' => ['required', 'string', 'max:200'],
            'consumer_secret' => ['required', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'serpro_contract_id' => ['sometimes', 'nullable', 'integer', 'exists:serpro_contracts,id'],
        ];
    }

    public function toDto(): CredentialVersionRegistrationData
    {
        $validated = $this->validated();
        $upload = $this->file('pfx');
        if (! $upload instanceof UploadedFile) {
            throw SerproConfigurationException::credentialRegistrationRejected();
        }

        $binary = file_get_contents($upload->getRealPath());
        if ($binary === false) {
            throw SerproConfigurationException::credentialRegistrationRejected();
        }

        return new CredentialVersionRegistrationData(
            environment: SerproEnvironment::from((string) $validated['environment']),
            pfxBinary: $binary,
            password: (string) $validated['password'],
            consumerKey: (string) $validated['consumer_key'],
            consumerSecret: (string) $validated['consumer_secret'],
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            contractId: isset($validated['serpro_contract_id'])
                ? (int) $validated['serpro_contract_id']
                : null,
        );
    }
}
