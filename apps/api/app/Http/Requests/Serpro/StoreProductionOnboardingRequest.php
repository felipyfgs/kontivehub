<?php

namespace App\Http\Requests\Serpro;

use App\DTO\Serpro\ProductionOnboardingInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

class StoreProductionOnboardingRequest extends FormRequest
{
    /** @var list<string> */
    private const TECHNICAL_FIELDS = [
        'office_id',
        'environment',
        'version_number',
        'serpro_contract_id',
        'approval_id',
        'skip_oauth',
        'endpoint',
        'token_url',
        'pfx_vault_object_id',
        'oauth_vault_object_id',
        'bearer',
        'jwt_token',
        'autenticar_procurador_token',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'consumer_key' => ['required', 'string', 'min:3', 'max:200'],
            'consumer_secret' => ['required', 'string', 'min:6', 'max:400'],
            'certificate' => ['required', 'file', 'max:5120', 'extensions:pfx,p12'],
            'certificate_password' => ['required', 'string', 'max:300'],
            'consent_granted' => ['required', 'accepted'],
        ];

        foreach (self::TECHNICAL_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'consent_granted.accepted' => 'O consentimento explícito é obrigatório para ativar a produção SERPRO.',
            '*.prohibited' => 'Campo técnico não aceito neste fluxo.',
        ];
    }

    public function idempotencyKey(): string
    {
        $raw = trim((string) $this->header('Idempotency-Key', ''));
        if ($raw === '') {
            $raw = trim((string) $this->header('X-Idempotency-Key', ''));
        }

        if ($raw === '') {
            return substr(hash('sha256', implode('|', [
                (string) $this->user()?->id,
                (string) $this->ip(),
                (string) $this->input('consumer_key'),
                (string) $this->file('certificate')?->getClientOriginalName(),
            ])), 0, 64);
        }

        validator(
            ['idempotency_key' => $raw],
            ['idempotency_key' => ['required', 'string', 'max:96', Rule::notIn(['office_id'])]],
        )->validate();

        return $raw;
    }

    public function toDto(): ProductionOnboardingInput
    {
        $file = $this->file('certificate');
        $path = $file?->getRealPath();
        if ($file === null || ! is_string($path)) {
            throw new RuntimeException('Arquivo PFX não encontrado.');
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            throw new RuntimeException('Falha ao ler arquivo PFX.');
        }

        return new ProductionOnboardingInput(
            consumerKey: trim((string) $this->input('consumer_key')),
            consumerSecret: trim((string) $this->input('consumer_secret')),
            pfxBinary: $binary,
            certificatePassword: (string) $this->input('certificate_password'),
            idempotencyKey: $this->idempotencyKey(),
            consentGranted: filter_var($this->input('consent_granted'), FILTER_VALIDATE_BOOL),
        );
    }
}
