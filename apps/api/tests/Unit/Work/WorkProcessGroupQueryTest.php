<?php

namespace Tests\Unit\Work;

use App\Services\Work\WorkProcessGroupQuery;
use App\Support\CurrentTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkProcessGroupQueryTest extends TestCase
{
    public function test_manual_constants_are_stable(): void
    {
        $this->assertSame('manual', WorkProcessGroupQuery::MANUAL_KEY);
        $this->assertSame('Sem rotina', WorkProcessGroupQuery::MANUAL_LABEL);
    }

    #[DataProvider('sortProvider')]
    public function test_resolve_sort_whitelist(?string $sort, ?string $direction, string $expectedSort, string $expectedDirection): void
    {
        $query = new WorkProcessGroupQuery($this->createMock(CurrentTenant::class));

        [$resolvedSort, $resolvedDirection] = $query->resolveSort($sort, $direction);

        $this->assertSame($expectedSort, $resolvedSort);
        $this->assertSame($expectedDirection, $resolvedDirection);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: string}>
     */
    public static function sortProvider(): array
    {
        return [
            'default' => [null, null, 'label', 'asc'],
            'progress desc' => ['progress_percent', 'desc', 'progress_percent', 'desc'],
            'open tasks' => ['open_task_count', 'ASC', 'open_task_count', 'asc'],
            'invalid falls back' => ['drop_table', 'desc', 'label', 'desc'],
            'direction invalid' => ['next_due_date', 'sideways', 'next_due_date', 'asc'],
        ];
    }
}
