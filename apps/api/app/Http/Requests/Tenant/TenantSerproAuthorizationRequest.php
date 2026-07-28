<?php

namespace App\Http\Requests\Tenant;

use App\Enums\SerproEnvironment;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\SerproTenantAccessPolicy;
use Illuminate\Validation\ValidationException;

abstract class TenantSerproAuthorizationRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        $policy = app(SerproTenantAccessPolicy::class);

        return $this->requiresMutationPermission()
            ? $policy->mutateTenantSerpro($actor)
            : $policy->viewTenantSerpro($actor);
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }

        $environment = $this->input('environment');
        if (is_string($environment) && $environment !== '') {
            $this->merge(['environment' => strtoupper($environment)]);
        }

        $this->prepareTenantSerproValidation();
    }

    public function environment(): SerproEnvironment
    {
        $raw = $this->input('environment');
        if (is_string($raw) && $raw !== '') {
            return SerproEnvironment::tryFrom(strtoupper($raw))
                ?? $this->defaultEnvironment();
        }

        return $this->defaultEnvironment();
    }

    protected function requiresMutationPermission(): bool
    {
        return false;
    }

    protected function prepareTenantSerproValidation(): void {}

    private function defaultEnvironment(): SerproEnvironment
    {
        return SerproEnvironment::from(
            (string) config('serpro.default_environment', SerproEnvironment::Trial->value),
        );
    }
}
