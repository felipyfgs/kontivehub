<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientListFilterData;
use App\Enums\TaxRegimeCode;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\ClientPolicy;

final class ListClientsRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientPolicy::class)->viewAny($actor);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'dashboard' => ['nullable'],
            'is_active' => ['nullable'],
            'operational_filter' => ['nullable'],
            'category_ids' => ['nullable'],
            'tax_regimes' => ['nullable'],
            'procuracao_statuses' => ['nullable'],
            'sort' => ['nullable'],
            'sort_direction' => ['nullable'],
            'per_page' => ['nullable'],
            'page' => ['nullable'],
        ];
    }

    public function toDto(): ClientListFilterData
    {
        $direction = $this->query('sort_direction', 'asc');

        return new ClientListFilterData(
            search: $this->string('q')->toString(),
            dashboard: $this->boolean('dashboard'),
            isActive: $this->filled('is_active') ? $this->boolean('is_active') : null,
            operationalFilter: $this->string('operational_filter')->toString(),
            categoryIds: $this->positiveIntegerCsv($this->query('category_ids'), 25),
            taxRegimes: $this->taxRegimeCsv($this->query('tax_regimes')),
            procuracaoStatuses: $this->procuracaoStatusCsv($this->query('procuracao_statuses')),
            sort: $this->string('sort', 'legal_name')->toString(),
            sortDirection: is_string($direction) && strtolower($direction) === 'desc'
                ? 'desc'
                : 'asc',
            perPage: min(max((int) $this->input('per_page', 20), 1), 100),
            page: max((int) $this->input('page', 1), 1),
        );
    }

    /** @return list<int> */
    private function positiveIntegerCsv(mixed $raw, int $limit): array
    {
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
        $ids = [];

        foreach ($parts as $part) {
            $text = trim((string) $part);
            if ($text === '' || ! ctype_digit($text) || (int) $text < 1) {
                continue;
            }

            $ids[(int) $text] = (int) $text;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return array_values($ids);
    }

    /** @return list<string> */
    private function taxRegimeCsv(mixed $raw): array
    {
        $allowed = TaxRegimeCode::currentProjectionValues();
        $values = [];
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');

        foreach ($parts as $part) {
            $value = strtoupper(trim((string) $part));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /** @return list<string> */
    private function procuracaoStatusCsv(mixed $raw): array
    {
        $allowed = ['authorized', 'expiring', 'expired', 'missing', 'unverified', 'verifying', 'failed'];
        $values = [];
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');

        foreach ($parts as $part) {
            $value = strtolower(trim((string) $part));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $values[$value] = $value;
            }
            if (count($values) >= 10) {
                break;
            }
        }

        return array_values($values);
    }
}
