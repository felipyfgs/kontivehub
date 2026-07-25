<?php

namespace Tests\Unit\Support;

use App\Enums\PlatformRole;
use App\Models\PlatformMembership;
use App\Support\MultitenantRbac\RoleStorageAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoleStorageAdapterTest extends TestCase
{
    public function test_explicit_platform_role_is_dual_written(): void
    {
        $membership = new PlatformMembership;

        (new RoleStorageAdapter)->dualWritePlatformMembership(
            $membership,
            PlatformRole::PlatformAdmin,
        );

        $this->assertSame(PlatformRole::PlatformAdmin, $membership->role);
        $this->assertSame(PlatformRole::PlatformAdmin, $membership->platform_role);
    }

    public function test_existing_valid_platform_role_is_dual_written(): void
    {
        $membership = new PlatformMembership;
        $membership->role = PlatformRole::PlatformAdmin;

        (new RoleStorageAdapter)->dualWritePlatformMembership($membership);

        $this->assertSame(PlatformRole::PlatformAdmin, $membership->role);
        $this->assertSame(PlatformRole::PlatformAdmin, $membership->platform_role);
    }

    public function test_unresolved_platform_role_fails_without_mutation(): void
    {
        $membership = new PlatformMembership;
        $adapter = new RoleStorageAdapter;

        try {
            $adapter->dualWritePlatformMembership($membership);
            $this->fail('Papel global ausente deveria falhar fechado.');
        } catch (RuntimeException $exception) {
            $this->assertSame('platform_role_unresolved', $exception->getMessage());
            $this->assertNull($membership->getAttribute('role'));
            $this->assertNull($membership->getAttribute('platform_role'));
        }
    }
}
