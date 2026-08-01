<?php

namespace App\Services\Communication\Contact;

use App\Enums\Communication\ProfilePictureState;
use App\Jobs\Communication\RefreshProfilePictureJob;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Media\MediaDeletionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Keeps provider observations scoped to an inbox without replacing curated contacts. */
final class InboxIdentityProfileMerger
{
    public function __construct(
        private readonly MediaDeletionService $deletions,
        private readonly EventRecorder $events,
    ) {}

    /** @var list<string> */
    private const FIELDS = [
        'address_book_first_name', 'address_book_full_name', 'verified_name',
        'business_name', 'push_name', 'picture_id', 'about',
    ];

    /**
     * @param  array<string, mixed>  $fields  Partial provider payload. Missing keys are preserved.
     * @param  list<string>  $clearedFields  Explicit removals from the provider.
     */
    public function merge(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        array $fields,
        CarbonInterface|string $observedAt,
        string $eventId,
        array $clearedFields = [],
    ): CommunicationInboxIdentityProfile {
        $canonicalIdentityId = (int) ($identity->canonical_identity_id ?: $identity->id);
        $occurredAt = ($observedAt instanceof CarbonInterface
            ? CarbonImmutable::instance($observedAt)
            : CarbonImmutable::parse($observedAt))
            ->utc()
            ->format('Y-m-d\TH:i:s.u\Z');

        return DB::transaction(function () use ($inbox, $canonicalIdentityId, $fields, $occurredAt, $eventId, $clearedFields): CommunicationInboxIdentityProfile {
            CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->whereKey($canonicalIdentityId)
                ->lockForUpdate()
                ->firstOrFail();
            $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('inbox_id', $inbox->id)
                ->where('identity_id', $canonicalIdentityId)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                $profile = new CommunicationInboxIdentityProfile([
                    'tenant_id' => $inbox->tenant_id,
                    'inbox_id' => $inbox->id,
                    'identity_id' => $canonicalIdentityId,
                    'field_versions' => [],
                    'cleared_fields' => [],
                ]);
            }

            $versions = is_array($profile->field_versions) ? $profile->field_versions : [];
            $cleared = array_fill_keys(is_array($profile->cleared_fields) ? $profile->cleared_fields : [], true);
            $pictureChanged = false;
            $abandonedObjectId = null;
            foreach (self::FIELDS as $field) {
                $isClear = in_array($field, $clearedFields, true);
                if (! $isClear && (! array_key_exists($field, $fields) || ! is_string($fields[$field]))) {
                    continue;
                }
                if (! $this->isNewer($versions[$field] ?? null, $occurredAt, $eventId)) {
                    continue;
                }
                $value = $isClear ? null : $this->normalise($fields[$field], $field);
                if (! $isClear && $value === null) {
                    continue;
                }
                $previous = $profile->{$field};
                $profile->{$field} = $value;
                $pictureChanged = $pictureChanged || ($field === 'picture_id' && $previous !== $value);
                $versions[$field] = ['observed_at' => $occurredAt, 'event_id' => $eventId];
                if ($isClear) {
                    $cleared[$field] = true;
                } else {
                    unset($cleared[$field]);
                }
            }
            $profile->field_versions = $versions;
            $profile->cleared_fields = array_values(array_keys($cleared));
            if ($pictureChanged) {
                $abandonedObjectId = $profile->profile_picture_object_id;
                $profile->profile_picture_state = $profile->picture_id === null ? ProfilePictureState::Unavailable : ProfilePictureState::Pending;
                $profile->profile_picture_version = (int) $profile->profile_picture_version + 1;
                $profile->profile_picture_object_id = null;
                $profile->profile_picture_mime_type = null;
                $profile->profile_picture_size_bytes = null;
                $profile->profile_picture_sha256 = null;
                $profile->profile_picture_storage_context = null;
                $profile->profile_picture_error_code = null;
                $profile->profile_picture_fetched_at = null;
                $profile->profile_picture_retry_at = null;
            }
            $profile->save();
            if ($pictureChanged) {
                $this->recordPictureUpdate($profile);
            }
            if ($pictureChanged && $profile->picture_id !== null) {
                RefreshProfilePictureJob::dispatch(
                    (int) $inbox->tenant_id,
                    (int) $profile->id,
                    (int) $profile->profile_picture_version,
                )->afterCommit();
            }
            if (is_string($abandonedObjectId) && $abandonedObjectId !== '') {
                $this->deletions->request($abandonedObjectId, (int) $inbox->tenant_id);
            }

            return $profile;
        });
    }

    /** Move the newest value of every source field to the canonical identity. */
    public function mergeFromDonor(
        CommunicationIdentity $survivor,
        CommunicationIdentity $donor,
    ): void {
        if ((int) $survivor->tenant_id !== (int) $donor->tenant_id) {
            throw new \LogicException('Identidades de tenants distintos não podem ser mescladas.');
        }

        $donor = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $survivor->tenant_id)
            ->findOrFail($donor->id);

        $profiles = CommunicationInboxIdentityProfile::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $donor->tenant_id)
            ->where('identity_id', $donor->id)
            ->orderBy('inbox_id')
            ->lockForUpdate()
            ->get();
        foreach ($profiles as $donorProfile) {
            DB::transaction(function () use ($donorProfile, $survivor, $donor): void {
                CommunicationIdentity::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $donor->tenant_id)
                    ->whereKey($survivor->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $source = CommunicationInboxIdentityProfile::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $donor->tenant_id)
                    ->lockForUpdate()
                    ->find($donorProfile->id);
                if ($source === null) {
                    return;
                }
                $target = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                    ->where('tenant_id', $donor->tenant_id)->where('inbox_id', $source->inbox_id)
                    ->where('identity_id', $survivor->id)->lockForUpdate()->first();
                if ($target === null) {
                    $source->identity_id = $survivor->id;
                    $source->save();
                    $this->recordPictureUpdate($source);

                    return;
                }

                $sourceVersions = is_array($source->field_versions) ? $source->field_versions : [];
                $targetVersions = is_array($target->field_versions) ? $target->field_versions : [];
                $sourcePictureVersion = $sourceVersions['picture_id'] ?? null;
                $sourceWinsPicture = is_array($sourcePictureVersion)
                    && isset($sourcePictureVersion['observed_at'], $sourcePictureVersion['event_id'])
                    && $this->isNewer(
                        $targetVersions['picture_id'] ?? null,
                        (string) $sourcePictureVersion['observed_at'],
                        (string) $sourcePictureVersion['event_id'],
                    );
                $sourceAsset = $source->profile_picture_object_id;
                $targetAsset = $target->profile_picture_object_id;
                if ($source->profile_picture_state === ProfilePictureState::Ready && $source->picture_id !== null && $sourceWinsPicture) {
                    foreach (self::FIELDS as $field) {
                        $version = $targetVersions[$field] ?? null;
                        if (is_array($version) && isset($version['observed_at'], $version['event_id'])
                            && $this->isNewer($sourceVersions[$field] ?? null, (string) $version['observed_at'], (string) $version['event_id'])) {
                            $source->{$field} = $target->{$field};
                            $sourceVersions[$field] = $version;
                        }
                    }
                    $source->field_versions = $sourceVersions;
                    if (is_string($targetAsset) && $targetAsset !== '' && $targetAsset !== $sourceAsset) {
                        $this->deletions->request($targetAsset, (int) $donor->tenant_id);
                    }
                    $target->delete();
                    $source->identity_id = $survivor->id;
                    $source->save();
                    $this->recordPictureUpdate($source);

                    return;
                }
                foreach (self::FIELDS as $field) {
                    $version = $sourceVersions[$field] ?? null;
                    if (is_array($version) && isset($version['observed_at'], $version['event_id'])
                        && $this->isNewer($targetVersions[$field] ?? null, (string) $version['observed_at'], (string) $version['event_id'])) {
                        $target->{$field} = $source->{$field};
                        $targetVersions[$field] = $version;
                    }
                }
                $target->field_versions = $targetVersions;
                if ($sourceWinsPicture) {
                    if (is_string($targetAsset) && $targetAsset !== '') {
                        $this->deletions->request($targetAsset, (int) $donor->tenant_id);
                    }
                    $target->profile_picture_state = $target->picture_id === null ? ProfilePictureState::Unavailable : ProfilePictureState::Pending;
                    $target->profile_picture_object_id = null;
                    $target->profile_picture_mime_type = null;
                    $target->profile_picture_size_bytes = null;
                    $target->profile_picture_sha256 = null;
                    $target->profile_picture_storage_context = null;
                    $target->profile_picture_error_code = null;
                    $target->profile_picture_fetched_at = null;
                    $target->profile_picture_retry_at = null;
                    $target->profile_picture_version = (int) $target->profile_picture_version + 1;
                }
                $target->save();
                if ($sourceWinsPicture) {
                    $this->recordPictureUpdate($target);
                }
                if ($sourceWinsPicture && $target->picture_id !== null) {
                    RefreshProfilePictureJob::dispatch(
                        (int) $donor->tenant_id,
                        (int) $target->id,
                        (int) $target->profile_picture_version,
                    )->afterCommit();
                }

                $source->delete();
                if (is_string($sourceAsset) && $sourceAsset !== '') {
                    $this->deletions->request($sourceAsset, (int) $donor->tenant_id);
                }
            });
        }
    }

    private function recordPictureUpdate(CommunicationInboxIdentityProfile $profile): void
    {
        $this->events->record(
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

    /** @param array<string, mixed>|mixed $current */
    private function isNewer(mixed $current, string $observedAt, string $eventId): bool
    {
        if (! is_array($current)) {
            return true;
        }
        $currentAt = (string) ($current['observed_at'] ?? '');
        $currentEvent = (string) ($current['event_id'] ?? '');

        return $observedAt > $currentAt
            || ($observedAt === $currentAt && strcmp($eventId, $currentEvent) > 0);
    }

    private function normalise(mixed $value, string $field): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $field === 'about' ? 2048 : 512);
    }
}
