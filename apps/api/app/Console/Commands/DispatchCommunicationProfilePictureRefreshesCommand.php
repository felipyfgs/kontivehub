<?php

namespace App\Console\Commands;

use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Enums\TenantLifecycleStatus;
use App\Jobs\Communication\RefreshCommunicationProfilePictureJob;
use App\Models\CommunicationInboxIdentityProfile;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchCommunicationProfilePictureRefreshesCommand extends Command
{
    protected $signature = 'communication:dispatch-profile-picture-refreshes';

    protected $description = 'Despacha refresh bounded de fotos de perfil WhatsApp.';

    public function handle(): int
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return self::SUCCESS;
        }

        $limit = max(1, min(100, (int) config('communication.profile_pictures.batch_size', 100)));
        $perInbox = max(1, min(25, (int) config('communication.profile_pictures.inbox_batch_size', 25)));
        $now = now();
        $refreshBefore = $now->copy()->subSeconds((int) config('communication.profile_pictures.refresh_ttl_seconds', 86_400));
        $canonicalIdentity = 'COALESCE(identities.canonical_identity_id, identities.id)';

        // Deduplicate aliases/conversations first, then apply the per-inbox quota
        // before the global limit. A fixed global pre-window can permanently
        // starve quiet inboxes behind one highly active inbox.
        $latestConversationPerIdentity = DB::table('communication_conversations as conversations')
            ->join('tenants', 'tenants.id', '=', 'conversations.tenant_id')
            ->join('communication_inboxes as inboxes', function ($join): void {
                $join->on('inboxes.id', '=', 'conversations.inbox_id')
                    ->on('inboxes.tenant_id', '=', 'conversations.tenant_id');
            })
            ->join('communication_identities as identities', function ($join): void {
                $join->on('identities.id', '=', 'conversations.identity_id')
                    ->on('identities.tenant_id', '=', 'conversations.tenant_id');
            })
            ->join('communication_identities as canonical_identities', function ($join) use ($canonicalIdentity): void {
                $join->on('canonical_identities.id', '=', DB::raw($canonicalIdentity))
                    ->on('canonical_identities.tenant_id', '=', 'conversations.tenant_id');
            })
            ->leftJoin('communication_inbox_identity_profiles as profiles', function ($join) use ($canonicalIdentity): void {
                $join->on('profiles.tenant_id', '=', 'conversations.tenant_id')
                    ->on('profiles.inbox_id', '=', 'conversations.inbox_id')
                    ->on('profiles.identity_id', '=', DB::raw($canonicalIdentity));
            })
            ->where('tenants.is_active', true)
            ->where('tenants.lifecycle_status', TenantLifecycleStatus::Active->value)
            ->where('tenants.communication_enabled', true)
            ->where('inboxes.is_enabled', true)
            ->where('inboxes.status', InboxStatus::Connected->value)
            ->where('canonical_identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('canonical_identities.is_active', true)
            ->whereNull('canonical_identities.purged_at')
            ->whereNull('conversations.purged_at')
            ->whereNull('conversations.merged_into_conversation_id')
            ->where(function ($due) use ($now, $refreshBefore): void {
                $due->whereNull('profiles.id')
                    ->orWhereIn('profiles.profile_picture_state', [
                        ProfilePictureState::Unknown->value,
                        ProfilePictureState::Pending->value,
                    ])
                    ->orWhere(function ($retry) use ($now): void {
                        $retry->whereIn('profiles.profile_picture_state', [
                            ProfilePictureState::Unavailable->value,
                            ProfilePictureState::Failed->value,
                        ])->whereNotNull('profiles.profile_picture_retry_at')
                            ->where('profiles.profile_picture_retry_at', '<=', $now);
                    })
                    ->orWhere(function ($ready) use ($now, $refreshBefore): void {
                        $ready->where('profiles.profile_picture_state', ProfilePictureState::Ready->value)
                            ->where(function ($refresh) use ($now, $refreshBefore): void {
                                $refresh->where(function ($retry) use ($now): void {
                                    $retry->whereNotNull('profiles.profile_picture_retry_at')
                                        ->where('profiles.profile_picture_retry_at', '<=', $now);
                                })->orWhere(function ($ttl) use ($refreshBefore): void {
                                    $ttl->whereNull('profiles.profile_picture_retry_at')
                                        ->where(function ($stale) use ($refreshBefore): void {
                                            $stale->whereNull('profiles.profile_picture_fetched_at')
                                                ->orWhere('profiles.profile_picture_fetched_at', '<=', $refreshBefore);
                                        });
                                });
                            });
                    });
            })
            ->select([
                'conversations.tenant_id',
                'conversations.inbox_id',
                DB::raw($canonicalIdentity.' as identity_id'),
                'conversations.last_message_at as activity_at',
                'conversations.id as conversation_id',
                DB::raw('ROW_NUMBER() OVER (
                    PARTITION BY conversations.tenant_id, conversations.inbox_id, '.$canonicalIdentity.'
                    ORDER BY conversations.last_message_at DESC NULLS LAST, conversations.id DESC
                ) as identity_rank'),
            ]);

        $profileWithoutConversation = DB::table('communication_inbox_identity_profiles as profiles')
            ->join('tenants', 'tenants.id', '=', 'profiles.tenant_id')
            ->join('communication_inboxes as inboxes', function ($join): void {
                $join->on('inboxes.id', '=', 'profiles.inbox_id')->on('inboxes.tenant_id', '=', 'profiles.tenant_id');
            })
            ->join('communication_identities as identities', function ($join): void {
                $join->on('identities.id', '=', 'profiles.identity_id')->on('identities.tenant_id', '=', 'profiles.tenant_id');
            })
            ->where('tenants.is_active', true)
            ->where('tenants.lifecycle_status', TenantLifecycleStatus::Active->value)
            ->where('tenants.communication_enabled', true)
            ->where('inboxes.is_enabled', true)
            ->where('inboxes.status', InboxStatus::Connected->value)
            ->where('identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('identities.is_active', true)
            ->whereNull('identities.canonical_identity_id')
            ->whereNull('identities.purged_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('communication_conversations as existing_conversations')
                    ->join('communication_identities as existing_identities', function ($join): void {
                        $join->on('existing_identities.id', '=', 'existing_conversations.identity_id')
                            ->on('existing_identities.tenant_id', '=', 'existing_conversations.tenant_id');
                    })
                    ->whereColumn('existing_conversations.tenant_id', 'profiles.tenant_id')
                    ->whereColumn('existing_conversations.inbox_id', 'profiles.inbox_id')
                    ->whereRaw('COALESCE(existing_identities.canonical_identity_id, existing_identities.id) = COALESCE(identities.canonical_identity_id, identities.id)')
                    ->whereNull('existing_conversations.purged_at')
                    ->whereNull('existing_conversations.merged_into_conversation_id');
            })
            ->where(function ($due) use ($now, $refreshBefore): void {
                $due->whereIn('profiles.profile_picture_state', [ProfilePictureState::Unknown->value, ProfilePictureState::Pending->value])
                    ->orWhere(fn ($retry) => $retry->whereIn('profiles.profile_picture_state', [ProfilePictureState::Unavailable->value, ProfilePictureState::Failed->value])->whereNotNull('profiles.profile_picture_retry_at')->where('profiles.profile_picture_retry_at', '<=', $now))
                    ->orWhere(function ($ready) use ($now, $refreshBefore): void {
                        $ready->where('profiles.profile_picture_state', ProfilePictureState::Ready->value)
                            ->where(function ($refresh) use ($now, $refreshBefore): void {
                                $refresh->where(function ($retry) use ($now): void {
                                    $retry->whereNotNull('profiles.profile_picture_retry_at')
                                        ->where('profiles.profile_picture_retry_at', '<=', $now);
                                })->orWhere(function ($ttl) use ($refreshBefore): void {
                                    $ttl->whereNull('profiles.profile_picture_retry_at')
                                        ->where(function ($stale) use ($refreshBefore): void {
                                            $stale->whereNull('profiles.profile_picture_fetched_at')
                                                ->orWhere('profiles.profile_picture_fetched_at', '<=', $refreshBefore);
                                        });
                                });
                            });
                    });
            })
            ->select([
                'profiles.tenant_id',
                'profiles.inbox_id',
                DB::raw('COALESCE(identities.canonical_identity_id, identities.id) as identity_id'),
                DB::raw('profiles.updated_at as activity_at'),
                DB::raw('0 as conversation_id'),
                DB::raw('1 as identity_rank'),
            ]);

        $allCandidates = $latestConversationPerIdentity->unionAll($profileWithoutConversation);
        $rankedPerInbox = DB::query()
            ->fromSub($allCandidates, 'identity_candidates')
            ->where('identity_rank', 1)
            ->select([
                'tenant_id',
                'inbox_id',
                'identity_id',
                'activity_at',
                'conversation_id',
                DB::raw('ROW_NUMBER() OVER (
                    PARTITION BY tenant_id, inbox_id
                    ORDER BY activity_at DESC NULLS LAST, conversation_id DESC
                ) as inbox_rank'),
            ]);

        $candidates = DB::query()
            ->fromSub($rankedPerInbox, 'ranked_candidates')
            ->where('inbox_rank', '<=', $perInbox)
            ->orderByRaw('activity_at DESC NULLS LAST')
            ->orderByDesc('conversation_id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $candidate) {
            $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->createOrFirst(
                [
                    'tenant_id' => (int) $candidate->tenant_id,
                    'inbox_id' => (int) $candidate->inbox_id,
                    'identity_id' => (int) $candidate->identity_id,
                ],
                [
                    'field_versions' => [],
                    'cleared_fields' => [],
                    'profile_picture_state' => ProfilePictureState::Unknown,
                    'profile_picture_version' => 1,
                ],
            );
            if ((int) $profile->profile_picture_version < 1) {
                CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                    ->whereKey($profile->id)
                    ->where('profile_picture_version', '<', 1)
                    ->update(['profile_picture_version' => 1]);
                $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->findOrFail($profile->id);
            }
            if (! $this->isDue($profile, $now, $refreshBefore)) {
                continue;
            }

            RefreshCommunicationProfilePictureJob::dispatch(
                (int) $profile->tenant_id,
                (int) $profile->id,
                (int) $profile->profile_picture_version,
            );
        }

        return self::SUCCESS;
    }

    private function isDue(
        CommunicationInboxIdentityProfile $profile,
        DateTimeInterface $now,
        DateTimeInterface $refreshBefore,
    ): bool {
        if (in_array($profile->profile_picture_state, [ProfilePictureState::Unknown, ProfilePictureState::Pending], true)) {
            return true;
        }
        if (in_array($profile->profile_picture_state, [ProfilePictureState::Unavailable, ProfilePictureState::Failed], true)) {
            return $profile->profile_picture_retry_at?->lte($now) === true;
        }
        if ($profile->profile_picture_state !== ProfilePictureState::Ready) {
            return false;
        }
        if ($profile->profile_picture_retry_at !== null) {
            return $profile->profile_picture_retry_at->lte($now);
        }

        return $profile->profile_picture_fetched_at === null
            || $profile->profile_picture_fetched_at->lte($refreshBefore);
    }
}
