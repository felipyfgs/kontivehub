<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\SerproAuthorConfigurationData;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class ConfigureTenantSerproAuthorRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'author_identity_type' => ['required', 'string', Rule::enum(AuthorIdentityType::class)],
            'author_identity' => ['required', 'string', 'max:14'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'certificate_mode' => ['sometimes', 'string', Rule::enum(AuthorCertificateMode::class)],
        ];
    }

    public function toDto(): SerproAuthorConfigurationData
    {
        return new SerproAuthorConfigurationData(
            environment: $this->environment(),
            identityType: AuthorIdentityType::from(
                (string) $this->validated('author_identity_type'),
            ),
            identity: (string) $this->validated('author_identity'),
            authorName: $this->validated('author_name'),
            certificateMode: $this->has('certificate_mode')
                ? AuthorCertificateMode::from((string) $this->validated('certificate_mode'))
                : AuthorCertificateMode::ExternalSignature,
            actorUserId: $this->actor()->id,
        );
    }
}
