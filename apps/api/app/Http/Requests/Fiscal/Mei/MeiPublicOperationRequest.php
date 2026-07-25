<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureOfficeContext;
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
            if ($this->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true) {
                $validator->errors()->add(
                    'office_id',
                    'O escritório é obtido do contexto autenticado; office_id não é aceito.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'office_id.prohibited' => 'O escritório é obtido do contexto autenticado; office_id não é aceito.',
            'confirmed.accepted' => 'A confirmação explícita é obrigatória.',
        ];
    }
}
