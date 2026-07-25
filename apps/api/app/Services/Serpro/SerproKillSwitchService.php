<?php

namespace App\Services\Serpro;

use App\Models\SerproRuntimeControl;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Kill switch global e por solução Integra Contador.
 *
 * Fonte de verdade: tabela serpro_runtime_controls (+ config env).
 * Redis/Cache é espelho para leitura rápida — flush/restart NÃO reabre o kill.
 * Não apaga contratos, tokens cifrados, Termos nem ledger.
 */
final class SerproKillSwitchService
{
    private const GLOBAL_CACHE_KEY = 'serpro.kill_switch.global';

    private const SOLUTION_PREFIX = 'serpro.kill_switch.solution.';

    private const GLOBAL_CONTROL_KEY = 'kill_switch.global';

    private const SOLUTION_CONTROL_PREFIX = 'kill_switch.solution.';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isGlobalActive(): bool
    {
        if ((bool) config('serpro.kill_switch', false)) {
            return true;
        }

        if ($this->loadPersistedActive(self::GLOBAL_CONTROL_KEY)) {
            return true;
        }

        return (bool) Cache::get(self::GLOBAL_CACHE_KEY, false);
    }

    public function isSolutionBlocked(string $solutionCode): bool
    {
        if ($this->isGlobalActive()) {
            return true;
        }

        $code = strtoupper($solutionCode);

        $configMap = config('serpro.solution_kill_switches', []);
        if (is_array($configMap)) {
            $normalized = [];
            foreach ($configMap as $key => $value) {
                $normalized[strtoupper((string) $key)] = $value;
            }
            if (! empty($normalized[$code])) {
                return true;
            }
        }
        if ($this->loadPersistedActive(self::SOLUTION_CONTROL_PREFIX.$code)) {
            return true;
        }

        return (bool) Cache::get(self::SOLUTION_PREFIX.$code, false);
    }

    public function activateGlobal(string $reason, ?int $userId = null): void
    {
        $this->persistControl(
            self::GLOBAL_CONTROL_KEY,
            'KILL_SWITCH',
            true,
            $reason,
            $userId,
        );
        Cache::forever(self::GLOBAL_CACHE_KEY, true);

        $this->audit->record('serpro.kill_switch.global_on', 'SUCCESS', null, [
            'reason' => mb_substr($reason, 0, 500),
            'durable' => true,
        ], $userId, null);
    }

    /**
     * Desativar kill switch global exige confirmação OWNER no caller
     * (senha recente + frase + motivo + janela). Ativação permanece imediata/fail-closed.
     * Este método só aplica o estado; SerproRolloutApprovalService garante a autorização.
     */
    public function deactivateGlobal(string $reason, ?int $userId = null): void
    {
        $this->persistControl(
            self::GLOBAL_CONTROL_KEY,
            'KILL_SWITCH',
            false,
            $reason,
            $userId,
        );
        Cache::forget(self::GLOBAL_CACHE_KEY);

        $this->audit->record('serpro.kill_switch.global_off', 'SUCCESS', null, [
            'reason' => mb_substr($reason, 0, 500),
            'durable' => true,
        ], $userId, null);
    }

    public function activateSolution(string $solutionCode, string $reason, ?int $userId = null): void
    {
        $code = strtoupper($solutionCode);
        $this->persistControl(
            self::SOLUTION_CONTROL_PREFIX.$code,
            'KILL_SWITCH_SOLUTION',
            true,
            $reason,
            $userId,
            ['solution' => $code],
        );
        Cache::forever(self::SOLUTION_PREFIX.$code, true);

        $this->audit->record('serpro.kill_switch.solution_on', 'SUCCESS', null, [
            'solution' => $code,
            'reason' => mb_substr($reason, 0, 500),
            'durable' => true,
        ], $userId, null);
    }

    public function deactivateSolution(string $solutionCode, string $reason, ?int $userId = null): void
    {
        $code = strtoupper($solutionCode);
        $this->persistControl(
            self::SOLUTION_CONTROL_PREFIX.$code,
            'KILL_SWITCH_SOLUTION',
            false,
            $reason,
            $userId,
            ['solution' => $code],
        );
        Cache::forget(self::SOLUTION_PREFIX.$code);

        $this->audit->record('serpro.kill_switch.solution_off', 'SUCCESS', null, [
            'solution' => $code,
            'reason' => mb_substr($reason, 0, 500),
            'durable' => true,
        ], $userId, null);
    }

    /**
     * Rehidrata espelho Redis a partir do DB (após flush/restart).
     */
    public function hydrateCacheFromDurable(): void
    {
        if (! Schema::hasTable('serpro_runtime_controls')) {
            return;
        }

        $rows = SerproRuntimeControl::query()
            ->where('control_type', 'KILL_SWITCH')
            ->orWhere('control_type', 'KILL_SWITCH_SOLUTION')
            ->get();

        foreach ($rows as $row) {
            if ($row->control_key === self::GLOBAL_CONTROL_KEY) {
                if ($row->active) {
                    Cache::forever(self::GLOBAL_CACHE_KEY, true);
                } else {
                    Cache::forget(self::GLOBAL_CACHE_KEY);
                }

                continue;
            }

            if (str_starts_with($row->control_key, self::SOLUTION_CONTROL_PREFIX)) {
                $code = substr($row->control_key, strlen(self::SOLUTION_CONTROL_PREFIX));
                if ($row->active) {
                    Cache::forever(self::SOLUTION_PREFIX.$code, true);
                } else {
                    Cache::forget(self::SOLUTION_PREFIX.$code);
                }
            }
        }
    }

    /**
     * @return array{
     *   global: array{active: bool, source: string|null, durable: bool},
     *   solutions: array<string, bool>
     * }
     */
    public function status(): array
    {
        $env = (bool) config('serpro.kill_switch', false);
        $durable = $this->loadPersistedActive(self::GLOBAL_CONTROL_KEY);
        $runtime = (bool) Cache::get(self::GLOBAL_CACHE_KEY, false);
        $active = $env || $durable || $runtime;

        $source = null;
        if ($env) {
            $source = 'config';
        } elseif ($durable) {
            $source = 'durable';
        } elseif ($runtime) {
            $source = 'runtime';
        }

        $solutions = is_array(config('serpro.solution_kill_switches'))
            ? array_map('boolval', config('serpro.solution_kill_switches'))
            : [];

        if (Schema::hasTable('serpro_runtime_controls')) {
            $rows = SerproRuntimeControl::query()
                ->where('control_type', 'KILL_SWITCH_SOLUTION')
                ->where('active', true)
                ->get();
            foreach ($rows as $row) {
                $code = str_starts_with($row->control_key, self::SOLUTION_CONTROL_PREFIX)
                    ? substr($row->control_key, strlen(self::SOLUTION_CONTROL_PREFIX))
                    : $row->control_key;
                $solutions[$code] = true;
            }
        }

        return [
            'global' => [
                'active' => $active,
                'source' => $source,
                'durable' => $durable || $env,
            ],
            'solutions' => $solutions,
        ];
    }

    private function loadPersistedActive(string $controlKey): bool
    {
        if (! Schema::hasTable('serpro_runtime_controls')) {
            return false;
        }

        try {
            $row = SerproRuntimeControl::query()
                ->where('control_key', $controlKey)
                ->first();

            return $row !== null && (bool) $row->active;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function persistControl(
        string $controlKey,
        string $controlType,
        bool $active,
        string $reason,
        ?int $userId,
        array $metadata = [],
    ): void {
        if (! Schema::hasTable('serpro_runtime_controls')) {
            return;
        }

        try {
            $now = now();
            SerproRuntimeControl::query()->updateOrCreate(
                ['control_key' => $controlKey],
                [
                    'control_type' => $controlType,
                    'active' => $active,
                    'source' => 'runtime',
                    'reason' => mb_substr($reason, 0, 500),
                    'updated_by_user_id' => $userId,
                    'activated_at' => $active ? $now : null,
                    'deactivated_at' => $active ? null : $now,
                    'metadata' => $metadata === [] ? null : $metadata,
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
