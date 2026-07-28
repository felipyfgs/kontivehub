<?php

namespace App\Http\Requests\Platform;

use App\Exceptions\DteCanaryApiException;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Contracts\Validation\Validator;

final class ExecuteDteCanaryRequest extends AuthenticatedRequest
{
    private const FORBIDDEN_FIELDS = [
        'tenant_id',
        'client_id',
        'operation_key',
        'id_sistema',
        'id_servico',
        'payload',
        'business_data',
    ];

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return array_fill_keys(
            self::FORBIDDEN_FIELDS,
            ['prohibited'],
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        foreach (self::FORBIDDEN_FIELDS as $field) {
            if ($this->exists($field)) {
                throw DteCanaryApiException::forbiddenField($field);
            }
        }

        parent::failedValidation($validator);
    }
}
