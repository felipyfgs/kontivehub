<?php

namespace App\Http\Requests\Communication;

use App\Services\Communication\Canned\CannedResponseRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreCannedResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'body' => ['required', 'string', 'max:4096'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = (string) $this->input('body', '');
            $disallowed = CannedResponseRenderer::disallowedPlaceholders($body);
            if ($disallowed !== []) {
                $validator->errors()->add(
                    'body',
                    'O corpo contém placeholders fora da allowlist: '.implode(', ', array_map(
                        static fn (string $token): string => '{{'.$token.'}}',
                        $disallowed,
                    )),
                );
            }
        });
    }
}
