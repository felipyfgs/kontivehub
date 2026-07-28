<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundPendingFilters;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundUrgencyBand;
use App\Rules\ValidOutboundCompetence;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class ListOutboundPendingRequest extends ViewOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['nullable', 'string', new ValidOutboundCompetence],
            'urgency_band' => ['nullable', Rule::enum(OutboundUrgencyBand::class)],
            'model' => ['nullable', Rule::in(['55', '65', 'NFE', 'NFCE'])],
            'root_cnpj' => ['nullable', 'string', 'max:18'],
            'client_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
            'source' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => [
                'nullable',
                Rule::in(['due_at', 'urgency_band', 'model', 'target_at', 'id']),
            ],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    protected function prepareOutboundValidation(): void
    {
        if (is_string($this->input('direction'))) {
            $this->merge([
                'direction' => strtolower($this->input('direction')),
            ]);
        }
    }

    public function filters(): OutboundPendingFilters
    {
        $validated = $this->validated();
        $model = isset($validated['model'])
            ? strtoupper((string) $validated['model'])
            : null;
        $root = isset($validated['root_cnpj'])
            ? strtoupper(preg_replace(
                '/[^A-Z0-9]/i',
                '',
                (string) $validated['root_cnpj'],
            ) ?? '')
            : null;
        $source = isset($validated['source'])
            ? strtoupper(trim((string) $validated['source']))
            : null;

        return new OutboundPendingFilters(
            competence: isset($validated['competence'])
                ? (string) $validated['competence']
                : null,
            urgencyBand: isset($validated['urgency_band'])
                ? OutboundUrgencyBand::from((string) $validated['urgency_band'])
                : null,
            model: match ($model) {
                '55', 'NFE' => OutboundFiscalModel::Nfe,
                '65', 'NFCE' => OutboundFiscalModel::Nfce,
                default => null,
            },
            rootCnpjPrefix: $root !== null && $root !== ''
                ? substr($root, 0, 8)
                : null,
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            source: $source !== '' ? $source : null,
            perPage: (int) ($validated['per_page'] ?? 50),
            sort: (string) ($validated['sort'] ?? 'due_at'),
            direction: (string) ($validated['direction'] ?? 'asc'),
        );
    }
}
