<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\TaxGuideIssueData;

final class IssueTaxGuideRequest extends TaxGuideWriteRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'operation_key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/',
            ],
            'competence_period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'debit_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'force_reissue' => ['sometimes', 'boolean'],
            'confirmation' => ['required', 'boolean'],
            'confirmation_summary' => ['required', 'array'],
            'confirmation_summary.client_id' => ['sometimes'],
            'confirmation_summary.competence_period_key' => ['sometimes'],
            'confirmation_summary.amount_cents' => ['sometimes'],
            'confirmation_summary.effect' => ['sometimes', 'string', 'max:255'],
            'operation_data' => ['sometimes', 'array'],
            'operation_data.uf' => ['sometimes', 'nullable', 'string', 'size:2'],
            'operation_data.municipio' => ['sometimes', 'nullable', 'string', 'max:100'],
            'operation_data.codigoReceita' => ['sometimes', 'string', 'max:10'],
            'operation_data.codigoReceitaExtensao' => ['sometimes', 'string', 'max:10'],
            'operation_data.numeroReferencia' => ['sometimes', 'nullable', 'string', 'max:30'],
            'operation_data.tipoPA' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_data.dataPA' => ['sometimes', 'date'],
            'operation_data.vencimento' => ['sometimes', 'nullable', 'date'],
            'operation_data.cota' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'operation_data.valorImposto' => ['sometimes', 'numeric', 'min:0'],
            'operation_data.valorMulta' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operation_data.valorJuros' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operation_data.ganhoCapital' => ['sometimes', 'boolean'],
            'operation_data.dataAlienacao' => ['sometimes', 'nullable', 'date'],
            'operation_data.dataConsolidacao' => ['sometimes', 'date'],
            'operation_data.observacao' => ['sometimes', 'nullable', 'string', 'max:200'],
            'operation_data.cno' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_data.cnpjPrestador' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function issueData(): TaxGuideIssueData
    {
        $data = $this->validated();

        return new TaxGuideIssueData(
            clientId: (int) $data['client_id'],
            operationKey: (string) $data['operation_key'],
            competencePeriodKey: isset($data['competence_period_key'])
                ? (string) $data['competence_period_key']
                : null,
            debitRef: isset($data['debit_ref'])
                ? (string) $data['debit_ref']
                : null,
            amountCents: isset($data['amount_cents'])
                ? (int) $data['amount_cents']
                : null,
            dueAtIso: isset($data['due_at'])
                ? (string) $data['due_at']
                : null,
            explicitConfirmation: (bool) $data['confirmation'],
            confirmationSummary: is_array($data['confirmation_summary'] ?? null)
                ? $data['confirmation_summary']
                : [],
            idempotencyKey: isset($data['idempotency_key'])
                ? (string) $data['idempotency_key']
                : null,
            correlationId: isset($data['correlation_id'])
                ? (string) $data['correlation_id']
                : null,
            forceReissue: (bool) ($data['force_reissue'] ?? false),
            operationData: is_array($data['operation_data'] ?? null)
                ? $data['operation_data']
                : [],
        );
    }
}
