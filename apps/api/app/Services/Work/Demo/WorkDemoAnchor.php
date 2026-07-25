<?php

namespace App\Services\Work\Demo;

use App\Models\Office;
use App\Support\Work\OfficeTimezone;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parser único da âncora temporal do seeder operacional.
 * Aceita Y-m-d; fallback = hoje civil no timezone do office.
 */
final class WorkDemoAnchor
{
    /**
     * Resolve a data âncora (Y-m-d) no timezone do office.
     *
     * @throws InvalidArgumentException se a variável estiver definida e for inválida
     */
    public function resolve(Office $office, ?string $raw = null): CarbonImmutable
    {
        $tz = OfficeTimezone::for($office);
        $candidate = $raw ?? config('work_demo.anchor_date');
        $candidate = is_string($candidate) ? trim($candidate) : null;

        if ($candidate === null || $candidate === '') {
            return CarbonImmutable::now($tz)->startOfDay();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            throw new InvalidArgumentException(
                "DEMO_WORK_ANCHOR_DATE inválida \"{$candidate}\": use Y-m-d."
            );
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $candidate, $tz);
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                "DEMO_WORK_ANCHOR_DATE inválida \"{$candidate}\": use Y-m-d."
            );
        }

        if ($date === false || $date->format('Y-m-d') !== $candidate) {
            throw new InvalidArgumentException(
                "DEMO_WORK_ANCHOR_DATE inválida \"{$candidate}\": data civil inexistente."
            );
        }

        return $date->startOfDay();
    }
}
