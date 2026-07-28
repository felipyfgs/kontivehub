<?php

namespace Tests\Unit\CodeQuality;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScheduleLockArchitectureTest extends TestCase
{
    #[Test]
    public function schedule_list_exposes_locked_events(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();
        self::assertNotEmpty($events);

        foreach ($events as $event) {
            $command = (string) ($event->command ?? $event->description ?? '');
            if (str_contains($command, 'ops:scheduler-heartbeat')) {
                continue;
            }

            self::assertTrue(
                $event->withoutOverlapping,
                "Schedule sem mutex de overlap: {$command}",
            );
            self::assertTrue(
                $event->releaseOnTerminationSignals,
                "Schedule sem liberação do mutex em sinal: {$command}",
            );
            self::assertTrue(
                $event->onOneServer,
                "Schedule sem singleton multi-réplica: {$command}",
            );
        }
    }

    #[Test]
    public function schedule_list_command_runs(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
    }

    #[Test]
    public function two_replicas_share_the_mutex_and_expired_locks_are_recoverable(): void
    {
        config(['cache.default' => 'array']);
        $mutex = new CacheEventMutex($this->app->make(CacheFactory::class));
        $firstReplica = new Event($mutex, 'php artisan test:scheduled-lock');
        $secondReplica = new Event($mutex, 'php artisan test:scheduled-lock');
        $firstReplica->withoutOverlapping(1, releaseOnTerminationSignals: true);
        $secondReplica->withoutOverlapping(1, releaseOnTerminationSignals: true);

        self::assertFalse($firstReplica->shouldSkipDueToOverlapping());
        self::assertTrue(
            $secondReplica->shouldSkipDueToOverlapping(),
            'A segunda réplica não pode adquirir o mesmo mutex ativo.',
        );

        $this->travel(61)->seconds();
        try {
            $afterExpiry = new Event($mutex, 'php artisan test:scheduled-lock');
            $afterExpiry->withoutOverlapping(1, releaseOnTerminationSignals: true);
            self::assertFalse(
                $afterExpiry->shouldSkipDueToOverlapping(),
                'Mutex expirado deve permitir recuperação por outra réplica.',
            );
            $mutex->forget($afterExpiry);
        } finally {
            $this->travelBack();
        }
    }
}
