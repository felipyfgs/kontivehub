<?php

namespace Tests\Unit\Fiscal;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FiscalProfilePolicyTest extends TestCase
{
    #[DataProvider('policyCases')]
    public function test_profile_policy(
        FiscalProfile $profile,
        FiscalOperationClass $operationClass,
        bool $officialTrialScenario,
        bool $expected,
    ): void {
        $this->assertSame($expected, $profile->allows($operationClass, $officialTrialScenario));
    }

    public static function policyCases(): array
    {
        return [
            'dev read' => [FiscalProfile::Dev, FiscalOperationClass::Read, true, true],
            'dev synthetic document' => [FiscalProfile::Dev, FiscalOperationClass::DocumentGeneration, false, true],
            'dev mutation' => [FiscalProfile::Dev, FiscalOperationClass::FiscalMutation, true, false],
            'trial read' => [FiscalProfile::Trial, FiscalOperationClass::Read, false, true],
            'trial official document' => [FiscalProfile::Trial, FiscalOperationClass::DocumentGeneration, true, true],
            'trial unknown document' => [FiscalProfile::Trial, FiscalOperationClass::DocumentGeneration, false, false],
            'production read' => [FiscalProfile::Production, FiscalOperationClass::Read, true, true],
            'production document' => [FiscalProfile::Production, FiscalOperationClass::DocumentGeneration, true, false],
            'production mutation' => [FiscalProfile::Production, FiscalOperationClass::FiscalMutation, true, false],
        ];
    }

    public function test_only_canonical_profiles_are_accepted(): void
    {
        config(['fiscal.profile' => 'sandbox']);

        $this->expectException(InvalidArgumentException::class);
        FiscalProfile::configured();
    }

    public function test_runtime_aliases_resolve_to_canonical_module_keys(): void
    {
        $this->assertSame(FiscalControlModule::Dctfweb, FiscalControlModule::fromRuntimeKey('dctfweb_mit'));
        $this->assertSame(FiscalControlModule::Mailbox, FiscalControlModule::fromRuntimeKey('mailbox'));
        $this->assertCount(10, FiscalControlModule::cases());
    }
}
