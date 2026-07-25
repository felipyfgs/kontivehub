<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Exceptions\RecentPasswordRequiredException;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Fiscal\Availability\FiscalModuleControlService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalModuleControlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admin_can_restrict(): void
    {
        $this->expectException(AuthorizationException::class);
        app(FiscalModuleControlService::class)->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Global,
            null,
            true,
            'Pausa',
            User::factory()->create(),
            false,
        );
    }

    public function test_release_requires_recent_password(): void
    {
        $actor = $this->platformAdmin();
        $service = app(FiscalModuleControlService::class);
        $service->setRestriction(FiscalControlModule::Mailbox, FiscalModuleControlScope::Global, null, true, 'Pausa', $actor, false);

        $this->expectException(RecentPasswordRequiredException::class);
        $service->setRestriction(FiscalControlModule::Mailbox, FiscalModuleControlScope::Global, null, false, 'Normalizado', $actor, false);
    }

    public function test_restriction_is_audited_and_blocked_jobs_are_counted(): void
    {
        $actor = $this->platformAdmin();
        $office = Office::factory()->create();
        $service = app(FiscalModuleControlService::class);
        $control = $service->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Office,
            $office,
            true,
            'Pausa operacional',
            $actor,
            false,
        );

        $service->recordBlockedJob(FiscalControlModule::Mailbox, $office, 'OFFICE_RESTRICTION', 42);

        $this->assertSame(1, $control->fresh()->blocked_jobs_count);
        $this->assertTrue(AuditLog::query()->where('action', 'fiscal.module.restricted')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'fiscal.module.job_blocked')->exists());
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        PlatformMembership::factory()->forUser($user)->create();

        return $user;
    }
}
