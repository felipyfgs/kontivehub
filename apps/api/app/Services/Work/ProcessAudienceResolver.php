<?php

namespace App\Services\Work;

use App\Domain\Work\ReferencePeriod;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientTaxRegimePeriod;
use App\Models\ProcessTemplate;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Resolve a carteira de um processo e produz evidência reproduzível da seleção. */
final class ProcessAudienceResolver
{
    private const MAX_CLIENTS = 1000;

    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    /**
     * @param  array<string, mixed>  $selection
     * @param  list<int>  $legacyClientIds
     * @return array{
     *   rules: array<string, mixed>,
     *   include_client_ids: list<int>,
     *   exclude_client_ids: list<int>,
     *   items: list<array<string, mixed>>,
     *   excluded_items: list<array<string, mixed>>,
     *   invalid_reference_count: int,
     *   stats: array<string, int>
     * }
     */
    public function resolve(
        ProcessTemplate $template,
        string $competence,
        array $selection = [],
        array $legacyClientIds = [],
    ): array {
        $officeId = $this->currentOffice->id();
        if ($officeId === null) {
            abort(404);
        }

        try {
            $period = ReferencePeriod::fromString($competence);
            $competenceDate = CarbonImmutable::createFromFormat('!Y-m-d', $period->startDate());
        } catch (InvalidArgumentException) {
            $competenceDate = false;
        }
        if ($competenceDate === false) {
            throw ValidationException::withMessages([
                'competence' => ['Competência inválida.'],
            ]);
        }

        $rulesInput = array_key_exists('rules', $selection)
            ? $selection['rules']
            : ($template->audience_rules ?? []);
        $rules = $this->normalizeRules(is_array($rulesInput) ? $rulesInput : []);

        $includeIds = $this->positiveIds([
            ...$legacyClientIds,
            ...($this->arrayValue($selection, 'include_client_ids')),
        ]);
        $excludeIds = $this->positiveIds($this->arrayValue($selection, 'exclude_client_ids'));
        $excludeMap = array_fill_keys($excludeIds, true);

        $ruleQuery = Client::query()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->with($this->clientRelations());

        $this->applyCategoryRules($ruleQuery, $rules);
        /** @var Collection<int, Client> $ruleCandidates */
        $ruleCandidates = $ruleQuery->limit(self::MAX_CLIENTS + 1)->get();
        if ($ruleCandidates->count() > self::MAX_CLIENTS) {
            throw ValidationException::withMessages([
                'selection' => ['A seleção ultrapassa 1.000 empresas; refine os filtros.'],
            ]);
        }

        /** @var Collection<int, Client> $includedClients */
        $includedClients = $includeIds === []
            ? collect()
            : Client::query()
                ->where('office_id', $officeId)
                ->whereKey($includeIds)
                ->with($this->clientRelations())
                ->get()
                ->keyBy('id');

        $invalidReferenceCount = max(0, count($includeIds) - $includedClients->count());
        $selected = [];
        $excluded = [];
        $matchedByRule = 0;
        $includedManually = 0;

        foreach ($ruleCandidates as $client) {
            $regime = $this->regimeAt($client, $competenceDate);
            if (! $this->matchesRegime($regime['code'], $rules['tax_regimes'])) {
                continue;
            }

            if (isset($excludeMap[(int) $client->id])) {
                $excluded[(int) $client->id] = $this->excludedItem($client, 'MANUAL_EXCLUDE');

                continue;
            }

            $isIncluded = in_array((int) $client->id, $includeIds, true);
            $selected[(int) $client->id] = $this->selectionItem(
                $client,
                $regime,
                $isIncluded ? 'RULE_AND_MANUAL_INCLUDE' : 'RULE',
            );
            $matchedByRule++;
        }

        foreach ($includeIds as $clientId) {
            /** @var Client|null $client */
            $client = $includedClients->get($clientId);
            if ($client === null) {
                continue;
            }
            if (isset($excludeMap[$clientId])) {
                $excluded[$clientId] = $this->excludedItem($client, 'MANUAL_EXCLUDE');
                unset($selected[$clientId]);

                continue;
            }
            if (isset($selected[$clientId])) {
                continue;
            }

            $regime = $this->regimeAt($client, $competenceDate);
            $item = $this->selectionItem($client, $regime, 'MANUAL_INCLUDE');
            if (! $client->is_active) {
                $item['is_blocked'] = true;
                $item['conflicts'][] = [
                    'code' => 'CLIENT_INACTIVE',
                    'message' => 'Empresa inativa.',
                ];
            }
            $selected[$clientId] = $item;
            $includedManually++;
        }

        foreach ($excludeIds as $clientId) {
            if (isset($excluded[$clientId])) {
                continue;
            }
            /** @var Client|null $client */
            $client = $includedClients->get($clientId);
            if ($client !== null) {
                $excluded[$clientId] = $this->excludedItem($client, 'MANUAL_EXCLUDE');
            }
        }

        $items = array_values($selected);
        usort($items, static fn (array $a, array $b): int => [
            mb_strtolower((string) $a['client_name']),
            (int) $a['client_id'],
        ] <=> [
            mb_strtolower((string) $b['client_name']),
            (int) $b['client_id'],
        ]);

        return [
            'rules' => $rules,
            'include_client_ids' => $includeIds,
            'exclude_client_ids' => $excludeIds,
            'items' => $items,
            'excluded_items' => array_values($excluded),
            'invalid_reference_count' => $invalidReferenceCount,
            'stats' => [
                'matched_by_rule' => $matchedByRule,
                'included_manually' => $includedManually,
                'excluded_manually' => count($excluded),
                'invalid_references' => $invalidReferenceCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{tax_regimes:list<string>,category_ids:list<int>,category_match:string,excluded_category_ids:list<int>}
     */
    public function normalizeRules(array $rules): array
    {
        $taxRegimes = [];
        foreach ($this->arrayValue($rules, 'tax_regimes') as $value) {
            $regime = TaxRegimeCode::tryFrom(mb_strtoupper(trim((string) $value)));
            if ($regime === null || $regime === TaxRegimeCode::Unknown) {
                throw ValidationException::withMessages([
                    'audience_rules.tax_regimes' => ['Um ou mais regimes tributários são inválidos.'],
                ]);
            }
            $taxRegimes[$regime->value] = $regime->value;
        }

        $categoryIds = $this->positiveIds($this->arrayValue($rules, 'category_ids'));
        $excludedCategoryIds = $this->positiveIds($this->arrayValue($rules, 'excluded_category_ids'));
        $categoryMatch = mb_strtoupper(trim((string) ($rules['category_match'] ?? 'ANY')));
        if (! in_array($categoryMatch, ['ANY', 'ALL'], true)) {
            throw ValidationException::withMessages([
                'audience_rules.category_match' => ['Use ANY ou ALL para combinar tags.'],
            ]);
        }

        $this->assertTenantCategories(array_values(array_unique([
            ...$categoryIds,
            ...$excludedCategoryIds,
        ])));

        return [
            'tax_regimes' => array_values($taxRegimes),
            'category_ids' => $categoryIds,
            'category_match' => $categoryMatch,
            'excluded_category_ids' => $excludedCategoryIds,
        ];
    }

    /** @return array<int, string> */
    private function clientRelations(): array
    {
        return [
            'categories' => fn ($query) => $query->select([
                'client_categories.id',
                'client_categories.name',
                'client_categories.color',
            ])->orderBy('name')->orderBy('client_categories.id'),
            'establishments' => fn ($query) => $query->select([
                'id',
                'client_id',
                'cnpj',
                'is_matrix',
            ])->orderByDesc('is_matrix')->orderBy('id'),
            'taxRegimePeriods' => fn ($query) => $query->orderByDesc('observed_at')->orderByDesc('id'),
        ];
    }

    /**
     * @param  Builder<Client>  $query
     * @param  array<string, mixed>  $rules
     */
    private function applyCategoryRules(Builder $query, array $rules): void
    {
        $categoryIds = $rules['category_ids'];
        if ($categoryIds !== []) {
            if ($rules['category_match'] === 'ALL') {
                foreach ($categoryIds as $categoryId) {
                    $query->whereHas('categories', fn ($category) => $category->whereKey($categoryId));
                }
            } else {
                $query->whereHas('categories', fn ($category) => $category->whereKey($categoryIds));
            }
        }

        if ($rules['excluded_category_ids'] !== []) {
            $query->whereDoesntHave(
                'categories',
                fn ($category) => $category->whereKey($rules['excluded_category_ids']),
            );
        }
    }

    /**
     * @return array{code:string,source:string,alerts:list<array{code:string,message:string}>}
     */
    private function regimeAt(Client $client, CarbonImmutable $competenceDate): array
    {
        /** @var Collection<int, ClientTaxRegimePeriod> $covering */
        $covering = $client->taxRegimePeriods
            ->filter(static fn (ClientTaxRegimePeriod $period): bool => $period->effective_from !== null
                && $period->effective_from->lte($competenceDate)
                && ($period->effective_to === null || $period->effective_to->gte($competenceDate)))
            ->values();

        if ($covering->isNotEmpty()) {
            $period = $covering->first();
            $alerts = [];
            if ($covering->count() > 1) {
                $alerts[] = [
                    'code' => 'REGIME_PERIOD_OVERLAP',
                    'message' => 'Há períodos tributários sobrepostos; foi usada a evidência mais recente.',
                ];
            }

            return [
                'code' => $period->regime_code?->value ?? TaxRegimeCode::Unknown->value,
                'source' => 'EFFECTIVE_PERIOD',
                'alerts' => $alerts,
            ];
        }

        $fallback = TaxRegimeCode::normalize(is_string($client->tax_regime) ? $client->tax_regime : null);
        if ($fallback === TaxRegimeCode::Unknown) {
            return [
                'code' => $fallback->value,
                'source' => 'UNKNOWN',
                'alerts' => [[
                    'code' => 'REGIME_UNKNOWN',
                    'message' => 'Regime tributário não conhecido para a competência.',
                ]],
            ];
        }

        return [
            'code' => $fallback->value,
            'source' => 'CURRENT_PROFILE_FALLBACK',
            'alerts' => [[
                'code' => 'REGIME_CURRENT_FALLBACK',
                'message' => 'Sem período tributário para a competência; usado o regime atual do cadastro.',
            ]],
        ];
    }

    /** @param list<string> $allowed */
    private function matchesRegime(string $regime, array $allowed): bool
    {
        return $allowed === [] || ($regime !== TaxRegimeCode::Unknown->value && in_array($regime, $allowed, true));
    }

    /**
     * @param  array{code:string,source:string,alerts:list<array{code:string,message:string}>}  $regime
     * @return array<string, mixed>
     */
    private function selectionItem(Client $client, array $regime, string $source): array
    {
        return [
            'client_id' => (int) $client->id,
            'client_name' => $client->displayLabel(),
            'cnpj_masked' => $this->clientCnpjMasked($client),
            'tax_regime' => $regime['code'],
            'regime_source' => $regime['source'],
            'categories' => $client->categories->map(static fn (ClientCategory $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'color' => (string) $category->color,
            ])->values()->all(),
            'selection_source' => $source,
            'is_blocked' => false,
            'alerts' => $regime['alerts'],
            'conflicts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function excludedItem(Client $client, string $reason): array
    {
        return [
            'client_id' => (int) $client->id,
            'client_name' => $client->displayLabel(),
            'cnpj_masked' => $this->clientCnpjMasked($client),
            'reason' => $reason,
        ];
    }

    private function clientCnpjMasked(Client $client): ?string
    {
        $establishment = $client->establishments->first();
        $digits = preg_replace('/\D+/', '', (string) ($establishment?->cnpj ?? '')) ?? '';
        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3)
            .'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
    }

    /** @param list<mixed> $values @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $values @return list<mixed> */
    private function arrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? array_values($values[$key]) : [];
    }

    /** @param list<int> $categoryIds */
    private function assertTenantCategories(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $count = ClientCategory::query()
            ->where('office_id', $this->currentOffice->id())
            ->where('is_active', true)
            ->whereKey($categoryIds)
            ->count();
        if ($count !== count($categoryIds)) {
            throw ValidationException::withMessages([
                'audience_rules.category_ids' => ['Uma ou mais tags não pertencem ao escritório atual ou estão inativas.'],
            ]);
        }
    }
}
