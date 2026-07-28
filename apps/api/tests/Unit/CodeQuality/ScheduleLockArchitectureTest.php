<?php

namespace Tests\Unit\CodeQuality;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
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

        $withMutex = 0;
        foreach ($events as $event) {
            $command = (string) ($event->command ?? $event->description ?? '');
            if (str_contains($command, 'ops:scheduler-heartbeat')) {
                continue;
            }
            if ($event->withoutOverlapping ?? false) {
                $withMutex++;
            } elseif (method_exists($event, 'mutexName') && $event->mutexName() !== null) {
                $withMutex++;
            } else {
                // Laravel armazena withoutOverlapping no event
                $reflection = new \ReflectionObject($event);
                if ($reflection->hasProperty('withoutOverlapping')) {
                    $prop = $reflection->getProperty('withoutOverlapping');
                    $prop->setAccessible(true);
                    if ($prop->getValue($event)) {
                        $withMutex++;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $withMutex, 'Esperados schedules com withoutOverlapping.');
    }

    #[Test]
    public function schedule_list_command_runs(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
    }
}
