<?php

namespace App\DTO\Fiscal;

use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use InvalidArgumentException;

final readonly class FiscalAdapterRequest
{
    /**
     * @param  array<string, mixed>  $progress  cursor/progresso da run
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Office $office,
        public Client $client,
        public FiscalMonitoringRun $run,
        public string $systemCode,
        public string $serviceCode,
        public string $operationCode,
        public FiscalTrigger $trigger,
        public ?FiscalCompetence $competence = null,
        public ?string $progressCursor = null,
        public array $progress = [],
        public array $context = [],
    ) {
        self::assertPositiveId($office->getKey(), 'office.id');
        self::assertPositiveId($client->getKey(), 'client.id');
        self::assertPositiveId($run->getKey(), 'run.id');

        self::assertSameId($client->office_id, $office->getKey(), 'client.office_id');
        self::assertSameId($run->office_id, $office->getKey(), 'run.office_id');
        self::assertSameId($run->client_id, $client->getKey(), 'run.client_id');

        self::assertCoordinate($systemCode, $run->system_code, 'system_code');
        self::assertCoordinate($serviceCode, $run->service_code, 'service_code');
        self::assertCoordinate($operationCode, $run->operation_code, 'operation_code');

        $runTrigger = $run->trigger instanceof FiscalTrigger
            ? $run->trigger
            : FiscalTrigger::tryFrom((string) $run->trigger);
        if ($runTrigger === null || $runTrigger !== $trigger) {
            throw new InvalidArgumentException('FiscalAdapterRequest exige trigger coerente com a run.');
        }

        self::assertCompetence($office, $client, $run, $competence);
    }

    private static function assertPositiveId(mixed $value, string $field): void
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException("FiscalAdapterRequest exige {$field} persistido.");
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException("FiscalAdapterRequest exige {$field} persistido.");
        }
    }

    private static function assertSameId(mixed $actual, mixed $expected, string $field): void
    {
        self::assertPositiveId($actual, $field);
        if ((int) $actual !== (int) $expected) {
            throw new InvalidArgumentException("FiscalAdapterRequest exige {$field} coerente.");
        }
    }

    private static function assertCoordinate(string $provided, mixed $persisted, string $field): void
    {
        if ($provided === '' || ! is_string($persisted) || $persisted === '' || $provided !== $persisted) {
            throw new InvalidArgumentException("FiscalAdapterRequest exige {$field} coerente com a run.");
        }
    }

    private static function assertCompetence(
        Office $office,
        Client $client,
        FiscalMonitoringRun $run,
        ?FiscalCompetence $competence,
    ): void {
        $runCompetenceId = $run->competence_id;
        if ($runCompetenceId === null && $competence === null) {
            return;
        }

        if ($runCompetenceId === null || $competence === null) {
            throw new InvalidArgumentException(
                'FiscalAdapterRequest exige presença de competência coerente com a run.',
            );
        }

        self::assertPositiveId($runCompetenceId, 'run.competence_id');
        self::assertPositiveId($competence->getKey(), 'competence.id');
        self::assertSameId($competence->getKey(), $runCompetenceId, 'competence.id');
        self::assertSameId($competence->office_id, $office->getKey(), 'competence.office_id');
        self::assertSameId($competence->client_id, $client->getKey(), 'competence.client_id');
    }
}
