<?php

namespace Tests\Unit\Communication;

use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\ProfilePictureState;
use App\Jobs\Communication\RefreshCommunicationProfilePictureJob;
use App\Services\Communication\Transport\HttpCommunicationTransport;
use ReflectionMethod;
use Tests\TestCase;

final class CommunicationProfilePictureConfigurationTest extends TestCase
{
    public function test_native_defaults_keep_only_operational_bounds(): void
    {
        self::assertNull(config('communication.profile_pictures.enabled'));
        self::assertNull(config('communication.profile_pictures.fetch_kill_switch'));
        self::assertNull(config('communication.profile_pictures.allowed_tenant_ids'));
        self::assertNull(config('communication.profile_pictures.allowed_hosts'));
        self::assertSame(2_097_152, config('communication.profile_pictures.max_bytes'));
        self::assertSame(4_096, config('communication.profile_pictures.max_dimension'));
        self::assertSame(90, config('communication.profile_pictures.gateway_timeout_seconds'));
        self::assertSame(86_400, config('communication.profile_pictures.negative_ttl_seconds'));
        self::assertSame(86_400, config('communication.profile_pictures.refresh_ttl_seconds'));
        self::assertSame(100, config('communication.profile_pictures.batch_size'));
        self::assertSame(25, config('communication.profile_pictures.inbox_batch_size'));
        self::assertSame(600, config('communication.profile_pictures.stream_rate_limit_per_minute'));
        self::assertSame(1_200, config('communication.profile_pictures.stream_ip_rate_limit_per_minute'));
    }

    public function test_profile_picture_query_outlives_the_wazync_internal_deadline(): void
    {
        config([
            'communication.gateway.timeout_seconds' => 10,
            'communication.profile_pictures.gateway_timeout_seconds' => 90,
        ]);
        $transport = app(HttpCommunicationTransport::class);
        $method = new ReflectionMethod($transport, 'queryTimeout');
        $profilePicture = new GatewayQueryData(
            'query-profile-picture-timeout',
            'session-query-0001',
            GatewayQueryType::ProfilePicture,
            ['user' => '+5511999991234', 'preview' => true],
        );
        $ordinary = new GatewayQueryData(
            'query-user-info-timeout',
            'session-query-0001',
            GatewayQueryType::UserInfo,
            ['users' => ['+5511999991234']],
        );

        self::assertSame(90, $method->invoke($transport, $profilePicture));
        self::assertSame(10, $method->invoke($transport, $ordinary));

        config(['communication.gateway.timeout_seconds' => 120]);

        self::assertSame(90, $method->invoke($transport, $profilePicture));
        self::assertSame(120, $method->invoke($transport, $ordinary));
    }

    public function test_state_cycle_has_only_the_supported_values(): void
    {
        self::assertSame(
            ['UNKNOWN', 'PENDING', 'READY', 'UNAVAILABLE', 'FAILED'],
            array_column(ProfilePictureState::cases(), 'value'),
        );
    }

    public function test_job_budget_outlives_all_allowed_remote_timeouts_and_overlap_lock_outlives_job(): void
    {
        $maximumGatewaySeconds = 90;
        $maximumDownloadSeconds = 30;
        $storageAndShutdownMarginSeconds = 15;

        self::assertGreaterThan(
            $maximumGatewaySeconds + $maximumDownloadSeconds + $storageAndShutdownMarginSeconds,
            RefreshCommunicationProfilePictureJob::TIMEOUT_SECONDS,
        );
        self::assertGreaterThan(
            RefreshCommunicationProfilePictureJob::TIMEOUT_SECONDS,
            RefreshCommunicationProfilePictureJob::LOCK_EXPIRES_SECONDS,
        );
    }
}
