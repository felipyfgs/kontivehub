<?php

namespace App\Services\Ops;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Readiness global de plataforma — sem Office, sem office_id, sem egress fiscal.
 */
final class ProductionReadinessService
{
    public const HEARTBEAT_CACHE_KEY = 'ops:scheduler:heartbeat';

    /**
     * @return array{
     *     ok: bool,
     *     checked_at: string,
     *     release_sha: string|null,
     *     checks: list<array{id: string, ok: bool, detail: string}>,
     *     issues: list<string>
     * }
     */
    public function evaluate(): array
    {
        $checks = [
            $this->checkEnvironment(),
            $this->checkDatabase(),
            $this->checkMigrations(),
            $this->checkRedis(),
            $this->checkHorizon(),
            $this->checkSchedulerHeartbeat(),
            $this->checkStorage(),
            $this->checkVault(),
            $this->checkMail(),
            $this->checkOnboarding(),
            $this->checkFiscalContainment(),
        ];

        $issues = [];
        foreach ($checks as $check) {
            if (! $check['ok']) {
                $issues[] = $check['id'].': '.$check['detail'];
            }
        }

        $releaseSha = (string) config('ops.release_sha', '');
        if ($releaseSha === '') {
            $releaseSha = (string) env('RELEASE_SHA', '');
        }

        return [
            'ok' => $issues === [],
            'checked_at' => now()->utc()->toIso8601String(),
            'release_sha' => $releaseSha !== '' ? $releaseSha : null,
            'checks' => $checks,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkEnvironment(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');

        if ($env !== 'production') {
            return ['id' => 'environment', 'ok' => false, 'detail' => 'app_env_not_production'];
        }

        if ($debug) {
            return ['id' => 'environment', 'ok' => false, 'detail' => 'app_debug_enabled'];
        }

        return ['id' => 'environment', 'ok' => true, 'detail' => 'production_debug_false'];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1 as ok');

            return ['id' => 'database', 'ok' => true, 'detail' => 'connected'];
        } catch (\Throwable) {
            return ['id' => 'database', 'ok' => false, 'detail' => 'connection_failed'];
        }
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkMigrations(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return ['id' => 'migrations', 'ok' => false, 'detail' => 'repository_missing'];
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);
            $count = count($pending);

            if ($count > 0) {
                return ['id' => 'migrations', 'ok' => false, 'detail' => 'pending_count='.$count];
            }

            return ['id' => 'migrations', 'ok' => true, 'detail' => 'up_to_date'];
        } catch (\Throwable) {
            return ['id' => 'migrations', 'ok' => false, 'detail' => 'check_failed'];
        }
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();
            $ok = $pong === true || $pong === 'PONG' || $pong === 1 || $pong === '+PONG';

            return [
                'id' => 'redis',
                'ok' => (bool) $ok,
                'detail' => $ok ? 'pong' : 'unexpected_response',
            ];
        } catch (\Throwable) {
            if ((string) config('app.env') !== 'production' && config('cache.default') === 'array') {
                return ['id' => 'redis', 'ok' => true, 'detail' => 'skipped_array_cache_non_production'];
            }

            return ['id' => 'redis', 'ok' => false, 'detail' => 'connection_failed'];
        }
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkHorizon(): array
    {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            return ['id' => 'horizon', 'ok' => false, 'detail' => 'package_missing'];
        }

        try {
            /** @var MasterSupervisorRepository $repo */
            $repo = app(MasterSupervisorRepository::class);
            $masters = $repo->all();
            $count = is_countable($masters) ? count($masters) : 0;

            if ($count < 1) {
                return ['id' => 'horizon', 'ok' => false, 'detail' => 'no_master_supervisor'];
            }

            return ['id' => 'horizon', 'ok' => true, 'detail' => 'masters='.$count];
        } catch (\Throwable) {
            return ['id' => 'horizon', 'ok' => false, 'detail' => 'check_failed'];
        }
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkSchedulerHeartbeat(): array
    {
        $key = (string) config('ops.scheduler_heartbeat.cache_key', self::HEARTBEAT_CACHE_KEY);
        $maxAge = (int) config('ops.scheduler_heartbeat.max_age_seconds', 180);
        $raw = Cache::get($key);

        if ($raw === null || $raw === '') {
            return ['id' => 'scheduler_heartbeat', 'ok' => false, 'detail' => 'missing'];
        }

        try {
            $at = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return ['id' => 'scheduler_heartbeat', 'ok' => false, 'detail' => 'invalid'];
        }

        $age = $at->diffInSeconds(now());
        if ($age > $maxAge) {
            return ['id' => 'scheduler_heartbeat', 'ok' => false, 'detail' => 'stale_age_seconds='.$age];
        }

        return ['id' => 'scheduler_heartbeat', 'ok' => true, 'detail' => 'age_seconds='.$age];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkStorage(): array
    {
        $path = storage_path('app/private');
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            return ['id' => 'storage', 'ok' => false, 'detail' => 'private_missing'];
        }

        $probe = $path.'/.ops-readiness-'.bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, 'ok') !== false;
        if ($written) {
            @unlink($probe);
        }

        return [
            'id' => 'storage',
            'ok' => $written,
            'detail' => $written ? 'writable' : 'not_writable',
        ];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkVault(): array
    {
        $root = (string) config('vault.disk_root', storage_path('app/vault'));
        if ($root === '') {
            return ['id' => 'vault', 'ok' => false, 'detail' => 'root_unset'];
        }

        if (! is_dir($root) && ! @mkdir($root, 0750, true) && ! is_dir($root)) {
            return ['id' => 'vault', 'ok' => false, 'detail' => 'missing'];
        }

        $probe = rtrim($root, '/').'/.ops-readiness-'.bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, 'ok') !== false;
        if ($written) {
            @unlink($probe);
        }

        $key = (string) config('vault.master_key', '');
        if ($key === '') {
            return ['id' => 'vault', 'ok' => false, 'detail' => 'master_key_missing'];
        }

        return [
            'id' => 'vault',
            'ok' => $written,
            'detail' => $written ? 'writable_key_present' : 'not_writable',
        ];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');

        if ((string) config('app.env') === 'production') {
            if ($mailer !== 'smtp') {
                return ['id' => 'mail', 'ok' => false, 'detail' => 'mailer_not_smtp'];
            }

            $host = (string) config('mail.mailers.smtp.host', '');
            $from = (string) config('mail.from.address', '');
            if ($host === '' || $from === '') {
                return ['id' => 'mail', 'ok' => false, 'detail' => 'smtp_incomplete'];
            }
        }

        return ['id' => 'mail', 'ok' => true, 'detail' => 'configured'];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkOnboarding(): array
    {
        $enabled = (bool) config('onboarding.enabled', false);
        $token = (string) config('onboarding.token', '');
        $completed = false;

        if (Schema::hasTable('platform_settings')) {
            $settings = PlatformSetting::query()->find(PlatformSetting::SINGLETON_ID);
            $completed = $settings !== null && $settings->onboarding_completed_at !== null;
        }

        $hasUsers = Schema::hasTable('users') && User::query()->exists();

        if ($completed || $hasUsers) {
            if ($enabled || $token !== '') {
                return [
                    'id' => 'onboarding',
                    'ok' => false,
                    'detail' => 'window_still_open_after_bootstrap',
                ];
            }

            return ['id' => 'onboarding', 'ok' => true, 'detail' => 'closed_after_bootstrap'];
        }

        if ($enabled) {
            if (strlen($token) < 32) {
                return ['id' => 'onboarding', 'ok' => false, 'detail' => 'token_too_weak'];
            }

            return ['id' => 'onboarding', 'ok' => true, 'detail' => 'window_open_empty_base'];
        }

        return ['id' => 'onboarding', 'ok' => true, 'detail' => 'disabled_empty_base'];
    }

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    private function checkFiscalContainment(): array
    {
        $issues = [];

        if ((bool) config('features.global_enabled', false) || FeatureFlags::isGloballyEnabled()) {
            $issues[] = 'features_global_enabled';
        }
        if ((bool) config('features.mutating.enabled', false)) {
            $issues[] = 'features_mutating_enabled';
        }
        if (! (bool) config('serpro.kill_switch', false)) {
            $issues[] = 'serpro_kill_switch_off';
        }

        foreach ((array) config('serpro.capabilities', []) as $name => $driver) {
            if (is_string($driver) && strtolower($driver) === 'real') {
                $issues[] = 'serpro_capability_real:'.$name;
            }
        }

        $topLevelChannels = [
            'distdfe_enabled' => 'sefaz_distdfe',
            'manifest_enabled' => 'sefaz_manifest',
            'cte_enabled' => 'sefaz_cte',
            'nfce_enabled' => 'sefaz_nfce',
        ];
        foreach ($topLevelChannels as $configKey => $label) {
            if ((bool) config('sefaz.'.$configKey, false)) {
                $issues[] = $label;
            }
        }

        $nestedChannels = [
            'ma_outbound.enabled' => 'sefaz_ma_outbound',
            'autxml.enabled' => 'sefaz_autxml',
            'cte_autxml.enabled' => 'sefaz_cte_autxml',
            'svrs_nfce_xml.retrieval_enabled' => 'sefaz_svrs_nfce_xml',
            'svrs_nfe55_xml.retrieval_enabled' => 'sefaz_svrs_nfe55_xml',
        ];
        foreach ($nestedChannels as $path => $label) {
            if ((bool) data_get(config('sefaz'), $path, false)) {
                $issues[] = $label;
            }
        }

        $issues = array_values(array_unique($issues));

        if ($issues !== []) {
            return [
                'id' => 'fiscal_containment',
                'ok' => false,
                'detail' => implode(',', $issues),
            ];
        }

        return ['id' => 'fiscal_containment', 'ok' => true, 'detail' => 'contained'];
    }
}
