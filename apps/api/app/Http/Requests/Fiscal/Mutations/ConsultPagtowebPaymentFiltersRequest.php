<?php

namespace App\Http\Requests\Fiscal\Mutations;

/**
 * Confirmação + filtros para contagem/lista PagtoWeb.
 * Nested filters are free-form beyond declared keys (service-side validation).
 */
final class ConsultPagtowebPaymentFiltersRequest extends ConfirmFiscalOperationRequest
{
    protected function shouldFailOnUnknownFields(): bool
    {
        return false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'filters' => ['required', 'array'],
            'filters.intervalo_data_arrecadacao' => ['sometimes', 'array'],
            'filters.intervalo_data_arrecadacao.data_inicial' => [
                'required_with:filters.intervalo_data_arrecadacao',
                'string',
            ],
            'filters.intervalo_data_arrecadacao.data_final' => [
                'required_with:filters.intervalo_data_arrecadacao',
                'string',
            ],
            'filters.intervalo_valor_total_documento' => ['sometimes', 'array'],
            'filters.intervalo_valor_total_documento.valor_inicial' => [
                'required_with:filters.intervalo_valor_total_documento',
                'numeric',
            ],
            'filters.intervalo_valor_total_documento.valor_final' => [
                'required_with:filters.intervalo_valor_total_documento',
                'numeric',
            ],
            'filters.codigo_receita_lista' => ['sometimes', 'array'],
            'filters.codigo_receita_lista.*' => ['string'],
            'filters.codigo_tipo_documento_lista' => ['sometimes', 'array'],
            'filters.codigo_tipo_documento_lista.*' => ['string'],
            'filters.numero_documento_lista' => ['sometimes', 'array', 'min:1', 'max:100'],
            'filters.numero_documento_lista.*' => ['string', 'regex:/^[0-9]{1,17}$/'],
            'filters.page' => ['sometimes', 'integer', 'min:1'],
            'filters.per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = $this->validated('filters');

        return is_array($filters) ? $filters : [];
    }
}
