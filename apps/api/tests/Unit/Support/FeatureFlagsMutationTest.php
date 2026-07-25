<?php

namespace Tests\Unit\Support;

use App\Support\FeatureFlags;
use Tests\TestCase;

final class FeatureFlagsMutationTest extends TestCase
{
    public function test_mutations_require_every_gate_and_tenant_cohort(): void
    {
        config([
            'fiscal.kill_switch' => false,
            'features.global_enabled' => true,
            'features.mutating.enabled' => true,
            'features.mutating.kill_switch' => false,
            'features.modules.simples_mei.enabled' => true,
            'features.modules.simples_mei.mutating_enabled' => true,
            'features.modules.simples_mei.office_allowlist' => [41],
            'features.modules.simples_mei.allow_all_offices' => false,
        ]);

        self::assertTrue(FeatureFlags::isMutatingEnabled('simples_mei', 41));
        self::assertFalse(FeatureFlags::isMutatingEnabled('simples_mei', 42));

        config()->set('features.mutating.kill_switch', true);
        self::assertFalse(FeatureFlags::isMutatingEnabled('simples_mei', 41));
    }

    public function test_mutations_remain_off_with_default_configuration(): void
    {
        config([
            'features.global_enabled' => false,
            'features.mutating.enabled' => false,
            'features.modules.simples_mei.enabled' => false,
            'features.modules.simples_mei.mutating_enabled' => false,
        ]);

        self::assertFalse(FeatureFlags::isMutatingEnabled('simples_mei', 1));
    }
}
