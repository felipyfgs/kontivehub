<?php

namespace App\Jobs\Communication;

use App\Contracts\CommunicationProfilePictureDownloader;
use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationProfilePictureDownloadException;
use App\Exceptions\CommunicationTransportException;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Services\Communication\Availability;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Media\MediaDeletionService;
use App\Services\Communication\Media\MediaStore;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The single coalescing boundary for profile observations; callers never fetch inline.
 */
final class RefreshProfilePictureJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 150;

    public const LOCK_EXPIRES_SECONDS = 180;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $uniqueFor = 900;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $tenantId, public readonly int $profileId, public readonly int $version)
    {
        $this->onQueue('communication');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->profileId.':'.$this->version;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('communication-profile-picture:'.$this->tenantId.':'.$this->profileId.':'.$this->version))
                ->releaseAfter(15)
                ->expireAfter(self::LOCK_EXPIRES_SECONDS),
        ];
    }

    public function handle(
        CommunicationTransport $transport,
        CommunicationProfilePictureDownloader $downloader,
        MediaStore $media,
        MediaDeletionService $deletions,
        ?Availability $availability = null,
    ): void {
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->find($this->profileId);
        if ($profile === null || (int) $profile->profile_picture_version !== $this->version) {
            return;
        }
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($profile->inbox_id);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($profile->identity_id);
        if ($inbox === null || $identity === null
            || $identity->channel !== CommunicationChannel::WhatsApp || ! $identity->is_active
            || $identity->purged_at !== null || ! is_string($identity->address_encrypted)
            || $identity->address_encrypted === '') {
            return;
        }
        try {
            ($availability ?? app(Availability::class))->assertEnabled($inbox, true);
        } catch (CommunicationUnavailableException) {
            return;
        }
        $stored = null;
        $downloaded = null;
        try {
            $queryId = 'query-'.strtolower((string) str()->ulid());
            $observedAt = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $result = $transport->query(new GatewayQueryData($queryId, (string) $inbox->session_id, GatewayQueryType::ProfilePicture, ['user' => $identity->address_encrypted, 'preview' => true]));
            $picture = $result['profile_picture'] ?? null;
            if ($picture === null) {
                $this->markUnavailableIfCurrent($deletions, 'UNAVAILABLE');

                return;
            }
            if (! is_array($picture) || ! is_string($picture['user'] ?? null)
                || ! hash_equals((string) $identity->address_encrypted, $picture['user'])
                || ! is_string($picture['url'] ?? null) || $picture['url'] === '') {
                throw new RuntimeException('PROFILE_PICTURE_RESULT_REJECTED');
            }
            $providerId = is_string($picture['id'] ?? null) ? trim($picture['id']) : null;
            if (! $this->observeProviderPicture(
                $providerId !== null && $providerId !== '' ? mb_substr($providerId, 0, 512) : null,
                $observedAt,
                $queryId,
                $deletions,
            )) {
                return;
            }
            $downloaded = $downloader->download($picture['url']);
            $context = ['tenant_id' => $this->tenantId, 'inbox_id' => (int) $profile->inbox_id, 'profile_id' => (int) $profile->id, 'version' => $this->version, 'purpose' => 'COMMUNICATION_MEDIA'];
            $stored = $media->putStream($downloaded->stream, $context);
            if ((int) $stored['size_bytes'] !== $downloaded->sizeBytes) {
                throw new RuntimeException('PROFILE_PICTURE_SIZE_MISMATCH');
            }
            DB::transaction(function () use ($profile, $stored, $downloaded, $context, $deletions): void {
                $fresh = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->lockForUpdate()->find($profile->id);
                if ($fresh === null || (int) $fresh->profile_picture_version !== $this->version) {
                    $deletions->request($stored['object_id'], $this->tenantId);

                    return;
                }
                $old = $fresh->profile_picture_object_id;
                $fresh->forceFill(['profile_picture_state' => ProfilePictureState::Ready, 'profile_picture_object_id' => $stored['object_id'], 'profile_picture_mime_type' => $downloaded->mimeType, 'profile_picture_size_bytes' => $stored['size_bytes'], 'profile_picture_sha256' => $stored['sha256'], 'profile_picture_storage_context' => $context, 'profile_picture_fetched_at' => now(), 'profile_picture_retry_at' => null, 'profile_picture_error_code' => null])->save();
                $this->recordUpdate($fresh);
                if (is_string($old) && $old !== '' && $old !== $stored['object_id']) {
                    $deletions->request($old, $this->tenantId);
                }
            });
        } catch (Throwable $error) {
            $promoted = is_array($stored)
                && is_string($stored['object_id'] ?? null)
                && $this->isCurrentObject($stored['object_id']);
            if (is_array($stored) && is_string($stored['object_id'] ?? null) && ! $promoted) {
                $deletions->request($stored['object_id'], $this->tenantId);
            }
            if ($promoted) {
                return;
            }
            $unavailable = ($error instanceof CommunicationProfilePictureDownloadException
                    && $error->safeCode === 'PROFILE_PICTURE_NOT_FOUND')
                || ($error instanceof CommunicationTransportException
                    && in_array($error->errorCode, ['QUERY_TARGET_NOT_FOUND', 'PROFILE_PICTURE_NOT_FOUND', 'PROFILE_PICTURE_PRIVACY'], true));
            if ($unavailable) {
                $this->markUnavailableIfCurrent($deletions, 'UNAVAILABLE');

                return;
            }
            $retryable = ($error instanceof CommunicationTransportException && $error->retryable)
                || ($error instanceof CommunicationProfilePictureDownloadException && $error->retryable);
            $this->updateFailurePreservingReady('FETCH_FAILED', now()->addMinutes(15));
            if ($retryable) {
                throw $error;
            }
            if ($error instanceof CommunicationTransportException
                || $error instanceof CommunicationProfilePictureDownloadException) {
                return;
            }

            throw $error;
        } finally {
            $downloaded?->close();
        }
    }

    public function tags(): array
    {
        return ['communication', 'profile-picture'];
    }

    private function observeProviderPicture(
        ?string $providerId,
        string $observedAt,
        string $eventId,
        MediaDeletionService $deletions,
    ): bool {
        return DB::transaction(function () use ($providerId, $observedAt, $eventId, $deletions): bool {
            $current = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->lockForUpdate()->find($this->profileId);
            if ($current === null || (int) $current->profile_picture_version !== $this->version) {
                return false;
            }
            if ($providerId === null || $providerId === $current->picture_id) {
                return true;
            }
            $versions = is_array($current->field_versions) ? $current->field_versions : [];
            $fieldVersion = is_array($versions['picture_id'] ?? null) ? $versions['picture_id'] : null;
            if ($fieldVersion !== null) {
                $currentAt = (string) ($fieldVersion['observed_at'] ?? '');
                $currentEvent = (string) ($fieldVersion['event_id'] ?? '');
                if ($observedAt < $currentAt || ($observedAt === $currentAt && strcmp($eventId, $currentEvent) <= 0)) {
                    return false;
                }
            }
            $versions['picture_id'] = ['observed_at' => $observedAt, 'event_id' => $eventId];
            if ($current->picture_id === null) {
                $cleared = array_values(array_diff(is_array($current->cleared_fields) ? $current->cleared_fields : [], ['picture_id']));
                $current->forceFill(['picture_id' => $providerId, 'field_versions' => $versions, 'cleared_fields' => $cleared])->save();

                return true;
            }
            $old = $current->profile_picture_object_id;
            $current->forceFill([
                'picture_id' => $providerId,
                'field_versions' => $versions,
                'cleared_fields' => array_values(array_diff(is_array($current->cleared_fields) ? $current->cleared_fields : [], ['picture_id'])),
                'profile_picture_state' => ProfilePictureState::Pending,
                'profile_picture_object_id' => null,
                'profile_picture_mime_type' => null,
                'profile_picture_size_bytes' => null,
                'profile_picture_sha256' => null,
                'profile_picture_storage_context' => null,
                'profile_picture_fetched_at' => null,
                'profile_picture_retry_at' => null,
                'profile_picture_error_code' => null,
                'profile_picture_version' => (int) $current->profile_picture_version + 1,
            ])->save();
            $this->recordUpdate($current);
            if (is_string($old) && $old !== '') {
                $deletions->request($old, $this->tenantId);
            }
            self::dispatch($this->tenantId, $this->profileId, (int) $current->profile_picture_version);

            return false;
        });
    }

    private function markUnavailableIfCurrent(MediaDeletionService $deletions, string $code): void
    {
        DB::transaction(function () use ($deletions, $code): void {
            $current = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->lockForUpdate()->find($this->profileId);
            if ($current === null || (int) $current->profile_picture_version !== $this->version) {
                return;
            }
            $old = $current->profile_picture_object_id;
            $current->forceFill([
                'profile_picture_state' => ProfilePictureState::Unavailable,
                'profile_picture_object_id' => null,
                'profile_picture_mime_type' => null,
                'profile_picture_size_bytes' => null,
                'profile_picture_sha256' => null,
                'profile_picture_storage_context' => null,
                'profile_picture_fetched_at' => null,
                'profile_picture_error_code' => $code,
                'profile_picture_retry_at' => now()->addSeconds((int) config('communication.profile_pictures.negative_ttl_seconds')),
            ])->save();
            $this->recordUpdate($current);
            if (is_string($old) && $old !== '') {
                $deletions->request($old, $this->tenantId);
            }
        });
    }

    private function updateFailurePreservingReady(string $code, DateTimeInterface $retryAt): void
    {
        DB::transaction(function () use ($code, $retryAt): void {
            $current = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->lockForUpdate()->find($this->profileId);
            if ($current === null || (int) $current->profile_picture_version !== $this->version) {
                return;
            }
            if ($current->profile_picture_state === ProfilePictureState::Ready) {
                $current->forceFill(['profile_picture_error_code' => $code, 'profile_picture_retry_at' => $retryAt])->save();

                return;
            }
            $current->forceFill(['profile_picture_state' => ProfilePictureState::Failed, 'profile_picture_error_code' => $code, 'profile_picture_retry_at' => $retryAt])->save();
            $this->recordUpdate($current);
        });
    }

    private function isCurrentObject(string $objectId): bool
    {
        return CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->profileId)
            ->where('profile_picture_version', $this->version)
            ->where('profile_picture_object_id', $objectId)
            ->where('profile_picture_state', ProfilePictureState::Ready->value)
            ->exists();
    }

    private function recordUpdate(CommunicationInboxIdentityProfile $profile): void
    {
        app(EventRecorder::class)->record(
            (int) $profile->tenant_id,
            'contact.profile_picture.updated',
            [
                'inbox_id' => (int) $profile->inbox_id,
                'identity_id' => (int) $profile->identity_id,
                'state' => $profile->profile_picture_state?->value ?? $profile->profile_picture_state,
                'version' => (int) $profile->profile_picture_version,
            ],
            (int) $profile->inbox_id,
        );
    }
}
