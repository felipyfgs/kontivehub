<?php

namespace App\DTO\Fiscal\Module;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\TaxInstallmentModality;

/**
 * Filtros normalizados da carteira/overview (mesmo escopo para contadores e lista).
 *
 * Eixos option e client_id aceitam valor único ou lista CSV / array
 * (ex.: situation=PENDING,ATTENTION; client_id=12,34).
 * Propriedades string guardam a forma canônica serializada (valores válidos, sorted, join `,`).
 */
final readonly class ModulePortfolioFilters
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 15,
        public ?string $q = null,
        public ?string $situation = null,
        public ?string $competence = null,
        public ?string $submodule = null,
        public ?string $deliveryStatus = null,
        public string $sort = 'legal_name',
        public string $sortDirection = 'asc',
        /** CSV de ids positivos canônicos (ex.: "12,34"); null = sem filtro. */
        public ?string $clientId = null,
        public ?string $coverage = null,
        public ?string $modality = null,
        /** Ano-calendário opcional da cápsula PGMEI. */
        public ?int $year = null,
        /**
         * Envio de comunicação PGDAS-D: sent | not_sent (CSV).
         * Só aplicado em simples_mei + submodule PGDASD.
         */
        public ?string $sendStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromRequest(array $input): self
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($input['per_page'] ?? 15)));

        $q = isset($input['q']) && is_string($input['q']) ? trim($input['q']) : null;
        if ($q === '') {
            $q = null;
        }

        $situation = self::normalizeTokenList(
            $input['situation'] ?? null,
            static fn (string $token): ?string => FiscalSituation::tryFrom(strtoupper($token))?->value,
        );

        $competence = isset($input['competence']) && is_string($input['competence'])
            ? trim($input['competence'])
            : null;
        if ($competence === '') {
            $competence = null;
        }

        $submodule = isset($input['submodule']) && is_string($input['submodule'])
            ? strtoupper(trim($input['submodule']))
            : null;
        if ($submodule === '') {
            $submodule = null;
        }

        $delivery = self::normalizeTokenList(
            $input['delivery_status'] ?? null,
            static fn (string $token): ?string => strtoupper(trim($token)) ?: null,
        );

        $sendStatus = self::normalizeTokenList(
            $input['send_status'] ?? null,
            static function (string $token): ?string {
                $normalized = strtolower(trim($token));

                return in_array($normalized, ['sent', 'not_sent'], true) ? $normalized : null;
            },
        );

        $sort = isset($input['sort']) && is_string($input['sort'])
            ? strtolower(trim($input['sort']))
            : 'legal_name';
        $allowedSort = ['legal_name', 'display_name', 'situation', 'last_declaration', 'rbt12', 'last_consulted_at', 'competence', 'id'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'legal_name';
        }

        $dir = isset($input['sort_direction']) && is_string($input['sort_direction'])
            ? strtolower(trim($input['sort_direction']))
            : 'asc';
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $clientId = self::normalizeTokenList(
            $input['client_id'] ?? null,
            static function (string $token): ?string {
                if (! is_numeric($token)) {
                    return null;
                }
                $id = (int) $token;

                return $id >= 1 ? (string) $id : null;
            },
        );

        $coverage = self::normalizeTokenList(
            $input['coverage'] ?? null,
            static fn (string $token): ?string => FiscalCoverage::tryFrom(strtoupper(trim($token)))?->value,
        );

        $modality = self::normalizeTokenList(
            $input['modality'] ?? null,
            static fn (string $token): ?string => TaxInstallmentModality::tryFrom(strtoupper(trim($token)))?->value,
        );

        $year = null;
        if (isset($input['year']) && (is_string($input['year']) || is_int($input['year']))) {
            $rawYear = trim((string) $input['year']);
            if (preg_match('/^\d{4}$/', $rawYear) === 1) {
                $candidate = (int) $rawYear;
                if ($candidate >= 2000 && $candidate <= 2100) {
                    $year = $candidate;
                }
            }
        }

        return new self(
            page: $page,
            perPage: $perPage,
            q: $q,
            situation: $situation,
            competence: $competence,
            submodule: $submodule,
            deliveryStatus: $delivery,
            sort: $sort,
            sortDirection: $dir,
            clientId: $clientId,
            coverage: $coverage,
            modality: $modality,
            year: $year,
            sendStatus: $sendStatus,
        );
    }

    /**
     * Cópia com paginação (export assíncrono itera páginas).
     */
    public function withPage(int $page, int $perPage = 100): self
    {
        return new self(
            page: max(1, $page),
            perPage: min(100, max(1, $perPage)),
            q: $this->q,
            situation: $this->situation,
            competence: $this->competence,
            submodule: $this->submodule,
            deliveryStatus: $this->deliveryStatus,
            sort: $this->sort,
            sortDirection: $this->sortDirection,
            clientId: $this->clientId,
            coverage: $this->coverage,
            modality: $this->modality,
            year: $this->year,
            sendStatus: $this->sendStatus,
        );
    }

    /**
     * @return list<string>
     */
    public function situationList(): array
    {
        return self::splitList($this->situation);
    }

    /**
     * @return list<int>
     */
    public function clientIdList(): array
    {
        $out = [];
        foreach (self::splitList($this->clientId) as $token) {
            if (! is_numeric($token)) {
                continue;
            }
            $id = (int) $token;
            if ($id >= 1) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function deliveryStatusList(): array
    {
        return self::splitList($this->deliveryStatus);
    }

    /**
     * @return list<string>
     */
    public function sendStatusList(): array
    {
        return self::splitList($this->sendStatus);
    }

    /**
     * @return list<string>
     */
    public function coverageList(): array
    {
        return self::splitList($this->coverage);
    }

    /**
     * @return list<string>
     */
    public function modalityList(): array
    {
        return self::splitList($this->modality);
    }

    /**
     * Compat: primeiro valor quando multi (ou único).
     */
    public function situationEnum(): ?FiscalSituation
    {
        $list = $this->situationList();
        if ($list === []) {
            return null;
        }

        return FiscalSituation::tryFrom($list[0]);
    }

    /**
     * @param  callable(string): (?string)  $map  normaliza token; null = descarta
     */
    private static function normalizeTokenList(mixed $raw, callable $map): ?string
    {
        $tokens = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $tokens[] = (string) $item;
                }
            }
        } elseif (is_string($raw)) {
            $tokens = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        } elseif (is_numeric($raw)) {
            $tokens = [(string) $raw];
        } else {
            return null;
        }

        $out = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || strcasecmp($token, 'all') === 0) {
                continue;
            }
            $mapped = $map($token);
            if ($mapped !== null && $mapped !== '') {
                $out[$mapped] = $mapped;
            }
        }

        if ($out === []) {
            return null;
        }

        $values = array_values($out);
        sort($values, SORT_STRING);

        return implode(',', $values);
    }

    /**
     * @return list<string>
     */
    private static function splitList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $part): bool => $part !== '',
        ));

        return $parts;
    }
}
