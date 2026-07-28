<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\DTO\Fiscal\Mutations\GenerateMeiDasData;
use App\Enums\TenantPermission;
use App\Models\User;
use Illuminate\Validation\Rule;

final class GenerateMeiDasPreflightRequest extends MeiPublicOperationRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('idempotency_key')) {
            $key = trim((string) $this->header('Idempotency-Key', ''));
            if ($key !== '') {
                $this->merge(['idempotency_key' => $key]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'competencies' => ['required', 'array', 'min:1', 'max:12'],
            'competencies.*' => ['required', 'string', 'distinct', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'due_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'output_format' => ['required', 'string', Rule::in(['PDF', 'BARCODE'])],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function generateData(): GenerateMeiDasData
    {
        $data = $this->validated();
        $competencies = array_values(array_map('strval', $data['competencies']));
        sort($competencies, SORT_STRING);

        return new GenerateMeiDasData(
            clientId: (int) $data['client_id'],
            competencies: $competencies,
            dueDate: is_string($data['due_date'] ?? null) && $data['due_date'] !== ''
                ? (string) $data['due_date']
                : now('America/Sao_Paulo')->toDateString(),
            outputFormat: (string) $data['output_format'],
            idempotencyKey: (string) $data['idempotency_key'],
            preflightToken: null,
            confirmationPhrase: null,
        );
    }

    public function actor(): User
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
