<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class MeiPublicOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, $this->permission());
    }

    abstract protected function permission(): TenantPermission;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true) {
                $validator->errors()->add(
                    'tenant_id',
                    'O escritório é obtido do contexto autenticado; tenant_id não é aceito.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tenant_id.prohibited' => 'O escritório é obtido do contexto autenticado; tenant_id não é aceito.',
            'confirmed.accepted' => 'A confirmação explícita é obrigatória.',
        ];
    }
}
