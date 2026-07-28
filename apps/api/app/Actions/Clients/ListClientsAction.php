<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientListFilterData;
use App\DTO\Clients\ClientListItemData;
use App\DTO\Clients\ClientListResult;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Establishment;
use App\Models\SyncCursor;
use App\Services\Integra\ClientProcuracaoValidityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class ListClientsAction
{
    public function __construct(
        private ClientProcuracaoValidityResolver $procuracoes,
    ) {}

    public function __invoke(ClientListFilterData $data): ClientListResult
    {
        $base = Client::query();
        $this->applySearch($base, $data->search);

        $statsQuery = clone $base;
        $stats = $this->stats($statsQuery, $data->dashboard);

        if ($data->isActive !== null) {
            $base->where('is_active', $data->isActive);
        }

        $this->applyOperationalFilter($base, $data->operationalFilter);

        if ($data->categoryIds !== []) {
            $base->whereHas('categories', fn (Builder $query) => $query->whereIn(
                'client_categories.id',
                $data->categoryIds,
            ));
        }

        if ($data->taxRegimes !== []) {
            $base->whereIn('tax_regime', $data->taxRegimes);
        }

        if ($data->procuracaoStatuses !== []) {
            $this->applyProcuracaoStatusesFilter($base, $data->procuracaoStatuses);
        }

        $sort = match ($data->sort) {
            'cnpj' => 'root_cnpj',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
            'tax_regime' => 'tax_regime',
            default => 'legal_name',
        };

        $paginator = (clone $base)
            ->withCount('establishments')
            ->with([
                'credential',
                'procuracaoSyncs' => fn ($query) => $query->where(
                    'environment',
                    (string) config('serpro.default_environment', 'TRIAL'),
                ),
                'categories' => fn ($query) => $query->orderBy('name')->orderBy('id'),
                'establishments' => fn ($query) => $query->orderBy('id')->with('syncCursors'),
            ])
            ->orderBy($sort, $data->sortDirection)
            ->orderBy('id')
            ->paginate($data->perPage, ['*'], 'page', $data->page)
            ->through(fn (Client $client) => new ClientListItemData(
                client: $client,
                procuracaoProjection: $this->procuracoes->resolve(
                    $client->procuracaoSyncs->first(),
                ),
                captureSummary: $this->buildCaptureSummary($client->establishments),
                syncSummary: $this->buildSyncSummary($client->establishments),
            ));

        return new ClientListResult($paginator, $stats);
    }

    /** @param Builder<Client> $query */
    private function applySearch(Builder $query, string $search): void
    {
        if (! $search) {
            return;
        }

        $needle = '%'.mb_strtolower($search).'%';
        $cnpjNeedle = '%'.strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $search) ?? $search).'%';

        $query->where(function (Builder $query) use ($needle, $cnpjNeedle): void {
            $query->whereRaw('LOWER(legal_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$needle])
                ->orWhere('root_cnpj', 'like', $cnpjNeedle)
                ->orWhereHas('establishments', function (Builder $establishments) use ($cnpjNeedle): void {
                    $establishments->where('cnpj', 'like', $cnpjNeedle);
                });
        });
    }

    /**
     * @param  Builder<Client>  $query
     * @return array<string, mixed>
     */
    private function stats(Builder $query, bool $dashboard): array
    {
        $stats = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'with_credential' => (clone $query)
                ->whereHas('credentials', self::operationalCredential(...))
                ->count(),
            'without_credential' => (clone $query)
                ->whereDoesntHave('credentials', self::operationalCredential(...))
                ->count(),
            'credential_expiring_30d' => (clone $query)
                ->whereHas('credentials', function (Builder $credentials): void {
                    $credentials->where('status', 'ACTIVE')
                        ->where(function (Builder $expiring): void {
                            $expiring->where('expires_alert_30', true)
                                ->orWhere('expires_alert_7', true)
                                ->orWhere('expires_alert_1', true)
                                ->orWhereBetween('valid_to', [now(), now()->addDays(30)]);
                        });
                })
                ->count(),
            'credential_expired' => (clone $query)
                ->whereHas('credentials', function (Builder $credentials): void {
                    $credentials->where(function (Builder $expired): void {
                        $expired->where('status', 'EXPIRED')
                            ->orWhere(function (Builder $activeExpired): void {
                                $activeExpired
                                    ->where('status', 'ACTIVE')
                                    ->where('valid_to', '<', now());
                            });
                    });
                })
                ->count(),
            'capture_problem' => (clone $query)
                ->whereHas('establishments.syncCursors', function (Builder $cursors): void {
                    $cursors->whereIn('status', [
                        SyncCursorStatus::Blocked->value,
                        SyncCursorStatus::Error->value,
                    ]);
                })
                ->count(),
        ];

        if (! $dashboard) {
            return $stats;
        }

        return [
            ...$stats,
            'credential_ok' => (clone $query)
                ->whereHas('credentials', function (Builder $credentials): void {
                    $credentials->where('status', 'ACTIVE')
                        ->where('valid_to', '>', now()->addDays(30))
                        ->where('expires_alert_30', false)
                        ->where('expires_alert_7', false)
                        ->where('expires_alert_1', false);
                })
                ->count(),
            'client_growth_12m' => $this->clientGrowthLastTwelveMonths($query),
        ];
    }

    /** @param Builder<Client> $query */
    private function applyOperationalFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'with_credential' => $query->whereHas(
                'credentials',
                self::operationalCredential(...),
            ),
            'without_credential' => $query->whereDoesntHave(
                'credentials',
                self::operationalCredential(...),
            ),
            'expiring' => $query->whereHas('credentials', function (Builder $credentials): void {
                $credentials->where('status', 'ACTIVE')
                    ->where(function (Builder $expiring): void {
                        $expiring->where('expires_alert_30', true)
                            ->orWhere('expires_alert_7', true)
                            ->orWhere('expires_alert_1', true)
                            ->orWhereBetween('valid_to', [now(), now()->addDays(30)]);
                    });
            }),
            'credential_expired' => $query->whereHas('credentials', function (Builder $credentials): void {
                $credentials->where(function (Builder $expired): void {
                    $expired->where('status', 'EXPIRED')
                        ->orWhere(function (Builder $activeExpired): void {
                            $activeExpired
                                ->where('status', 'ACTIVE')
                                ->where('valid_to', '<', now());
                        });
                });
            }),
            'capture_problem' => $query->whereHas(
                'establishments.syncCursors',
                function (Builder $cursors): void {
                    $cursors->whereIn('status', [
                        SyncCursorStatus::Blocked->value,
                        SyncCursorStatus::Error->value,
                    ]);
                },
            ),
            default => null,
        };
    }

    /** @param Builder<ClientCredential> $credentials */
    private static function operationalCredential(Builder $credentials): void
    {
        $credentials->whereIn('status', [
            CredentialStatus::Active,
            CredentialStatus::Pending,
        ]);
    }

    /**
     * @param  Builder<Client>  $base
     * @param  list<string>  $statuses
     */
    private function applyProcuracaoStatusesFilter(Builder $base, array $statuses): void
    {
        $environment = (string) config('serpro.default_environment', 'TRIAL');
        $now = now();
        $horizon = now()->addDays(30);

        $base->where(function (Builder $outer) use ($statuses, $environment, $now, $horizon): void {
            foreach ($statuses as $status) {
                $outer->orWhere(function (Builder $branch) use ($status, $environment, $now, $horizon): void {
                    match ($status) {
                        'authorized' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source
                                ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                ->where(function (Builder $valid) use ($horizon): void {
                                    $valid->whereNull('valid_to')
                                        ->orWhere('valid_to', '>', $horizon);
                                }),
                        ),
                        'expiring' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source
                                ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                ->where('valid_to', '>', $now)
                                ->where('valid_to', '<=', $horizon),
                        ),
                        'expired' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(function (Builder $expired) use ($now): void {
                                $expired->where('status', ClientProcuracaoSyncStatus::Expired->value)
                                    ->orWhere(function (Builder $authorizedExpired) use ($now): void {
                                        $authorizedExpired
                                            ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                            ->whereNotNull('valid_to')
                                            ->where('valid_to', '<=', $now);
                                    });
                            }),
                        ),
                        'missing' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Missing->value,
                            ),
                        ),
                        'unverified' => $branch->where(function (Builder $unverified) use ($environment): void {
                            $unverified
                                ->where(function (Builder $absent) use ($environment): void {
                                    $absent->whereDoesntHave(
                                        'procuracaoSyncs',
                                        fn (Builder $sync) => $sync->where('environment', $environment),
                                    );
                                })
                                ->orWhere(function (Builder $explicit) use ($environment): void {
                                    $this->whereProjectedProcuracao(
                                        $explicit,
                                        $environment,
                                        fn (Builder $source) => $source->where(
                                            'status',
                                            ClientProcuracaoSyncStatus::Unverified->value,
                                        ),
                                    );
                                });
                        }),
                        'verifying' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Verifying->value,
                            ),
                        ),
                        'failed' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Failed->value,
                            ),
                        ),
                        default => null,
                    };
                });
            }
        });
    }

    /**
     * @param  Builder<Client>  $branch
     * @param  callable(Builder): void  $constrain
     */
    private function whereProjectedProcuracao(Builder $branch, string $environment, callable $constrain): void
    {
        $branch->whereHas('procuracaoSyncs', function (Builder $sync) use ($environment, $constrain): void {
            $sync->where('environment', $environment);
            $constrain($sync);
        });
    }

    /**
     * @param  Builder<Client>  $query
     * @return list<array{month: string, total: int}>
     */
    private function clientGrowthLastTwelveMonths(Builder $query): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $monthExpression = "to_char(created_at, 'YYYY-MM')";
        $counts = (clone $query)
            ->where('created_at', '>=', $start)
            ->selectRaw("{$monthExpression} as month_key, COUNT(*) as aggregate")
            ->groupByRaw($monthExpression)
            ->pluck('aggregate', 'month_key');

        $cumulative = (clone $query)->where('created_at', '<', $start)->count();
        $series = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $start->copy()->addMonths($offset)->format('Y-m');
            $cumulative += (int) ($counts[$month] ?? 0);
            $series[] = ['month' => $month, 'total' => $cumulative];
        }

        return $series;
    }

    /**
     * @param  Collection<int, Establishment>  $establishments
     * @return array{enabled: bool, status: string, establishments_total: int, establishments_enabled: int}
     */
    private function buildCaptureSummary(Collection $establishments): array
    {
        $total = $establishments->count();
        $enabled = $establishments
            ->filter(fn (Establishment $establishment) => $establishment->is_active
                && $establishment->capture_enabled)
            ->count();

        return [
            'enabled' => $enabled > 0,
            'status' => match (true) {
                $total === 0 => 'NONE',
                $enabled === 0 => 'OFF',
                $enabled === $total => 'ON',
                default => 'PARTIAL',
            },
            'establishments_total' => $total,
            'establishments_enabled' => $enabled,
        ];
    }

    /**
     * @param  Collection<int, Establishment>  $establishments
     * @return array{status: string, last_success_at: ?string, has_cursor: bool}
     */
    private function buildSyncSummary(Collection $establishments): array
    {
        /** @var Collection<int, SyncCursor> $cursors */
        $cursors = $establishments->flatMap(
            fn (Establishment $establishment) => $establishment->relationLoaded('syncCursors')
                ? $establishment->syncCursors
                : collect(),
        );

        if ($cursors->isEmpty()) {
            return [
                'status' => 'NONE',
                'last_success_at' => null,
                'has_cursor' => false,
            ];
        }

        $rank = [
            SyncCursorStatus::Blocked->value => 50,
            SyncCursorStatus::Error->value => 40,
            SyncCursorStatus::Running->value => 30,
            SyncCursorStatus::Waiting->value => 20,
            SyncCursorStatus::Idle->value => 10,
        ];
        $worst = 'IDLE';
        $worstRank = -1;
        $lastSuccess = null;

        foreach ($cursors as $cursor) {
            $status = $cursor->status instanceof SyncCursorStatus
                ? $cursor->status->value
                : (string) $cursor->status;
            $cursorRank = $rank[$status] ?? 0;

            if ($cursorRank > $worstRank) {
                $worstRank = $cursorRank;
                $worst = $status;
            }

            $at = $cursor->last_success_at;
            if ($at !== null && ($lastSuccess === null || $at->gt($lastSuccess))) {
                $lastSuccess = $at;
            }
        }

        return [
            'status' => $worst,
            'last_success_at' => $lastSuccess?->toIso8601String(),
            'has_cursor' => true,
        ];
    }
}
