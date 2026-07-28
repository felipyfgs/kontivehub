<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Params públicos são validados pelo DeclarationOperationInputValidator;
 * o fail-on-unknown global não se aplica a chaves aninhadas livres.
 */
abstract class DeclarationOperationRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                $this->permission(),
            );
    }

    abstract protected function permission(): TenantPermission;

    /** @return array<string, list<mixed>> */
    abstract public function rules(): array;

    /**
     * Nested `params` are schema-validated later; only top-level exactness is enforced here.
     */
    protected function shouldFailOnUnknownFields(): bool
    {
        return false;
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            $unknown = array_diff(array_keys($this->all()), $allowed);
            if ($unknown === []) {
                return;
            }

            $validator->errors()->add(
                'request',
                'Campos não permitidos: '.implode(', ', $unknown).'.',
            );
        });
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão para esta operação fiscal.');
    }
}
