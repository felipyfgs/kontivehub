<?php

namespace App\Services\Communication\Contact;

use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Keeps provider observations scoped to an inbox without replacing curated contacts. */
final class CommunicationInboxIdentityProfileMerger
{
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
                $profile->{$field} = $value;
                $versions[$field] = ['observed_at' => $occurredAt, 'event_id' => $eventId];
                if ($isClear) {
                    $cleared[$field] = true;
                } else {
                    unset($cleared[$field]);
                }
            }
            $profile->field_versions = $versions;
            $profile->cleared_fields = array_values(array_keys($cleared));
            $profile->save();

            return $profile;
        });
    }

    /** Move the newest value of every source field to the canonical identity. */
    public function mergeFromDonor(
        CommunicationIdentity $survivor,
        CommunicationIdentity $donor,
    ): void
    {
        $profiles = CommunicationInboxIdentityProfile::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $donor->tenant_id)
            ->where('identity_id', $donor->id)
            ->orderBy('inbox_id')
            ->lockForUpdate()
            ->get();
        foreach ($profiles as $donorProfile) {
            $inbox = CommunicationInbox::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $donor->tenant_id)
                ->find($donorProfile->inbox_id);
            if ($inbox === null) {
                continue;
            }
            $versions = is_array($donorProfile->field_versions) ? $donorProfile->field_versions : [];
            foreach (self::FIELDS as $field) {
                $version = $versions[$field] ?? null;
                if (! is_array($version)
                    || ! is_string($version['observed_at'] ?? null)
                    || ! is_string($version['event_id'] ?? null)) {
                    continue;
                }
                $this->merge(
                    $inbox,
                    $survivor,
                    [$field => $donorProfile->{$field}],
                    $version['observed_at'],
                    $version['event_id'],
                    $donorProfile->{$field} === null ? [$field] : [],
                );
            }
            $donorProfile->delete();
        }
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
