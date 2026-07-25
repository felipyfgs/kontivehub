package protocol

import (
	"context"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow/appstate"
	"go.mau.fi/whatsmeow/proto/waCommon"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

type fakeAccountPolicyClient struct {
	fakeClient
	blockJID     types.JID
	blockAction  events.BlocklistChangeAction
	privacyName  types.PrivacySettingType
	privacyValue types.PrivacySetting
	defaultTimer time.Duration
	patch        appstate.PatchInfo
	fetched      []appstate.WAPatchName
	cleanType    string
	cleanAt      time.Time
}

func (c *fakeAccountPolicyClient) UpdateBlocklist(
	_ context.Context,
	jid types.JID,
	action events.BlocklistChangeAction,
) (*types.Blocklist, error) {
	c.blockJID, c.blockAction = jid, action
	return &types.Blocklist{JIDs: []types.JID{jid}}, nil
}

func (c *fakeAccountPolicyClient) SetPrivacySetting(
	_ context.Context,
	name types.PrivacySettingType,
	value types.PrivacySetting,
) (types.PrivacySettings, error) {
	c.privacyName, c.privacyValue = name, value
	return types.PrivacySettings{}, nil
}

func (c *fakeAccountPolicyClient) SetDefaultDisappearingTimer(
	_ context.Context,
	timer time.Duration,
) error {
	c.defaultTimer = timer
	return nil
}

func (c *fakeAccountPolicyClient) SendAppState(_ context.Context, patch appstate.PatchInfo) error {
	c.patch = patch
	return nil
}

func (c *fakeAccountPolicyClient) FetchAppState(
	_ context.Context,
	name appstate.WAPatchName,
	_, _ bool,
) error {
	c.fetched = append(c.fetched, name)
	return nil
}

func (c *fakeAccountPolicyClient) MarkNotDirty(_ context.Context, cleanType string, at time.Time) error {
	c.cleanType, c.cleanAt = cleanType, at
	return nil
}

func (c *fakeAccountPolicyClient) BuildMessageKey(
	chat, sender types.JID,
	id types.MessageID,
) *waCommon.MessageKey {
	return &waCommon.MessageKey{
		RemoteJID: proto.String(chat.String()), Participant: proto.String(sender.String()),
		ID: proto.String(id),
	}
}

func (c *fakeAccountPolicyClient) GetPNForLID(context.Context, types.JID) (types.JID, error) {
	return types.NewJID("5511999991234", types.DefaultUserServer), nil
}

func TestAccountPolicyResolvesLIDToPNAndUsesClosedPrivacyMatrix(t *testing.T) {
	t.Parallel()
	client := &fakeAccountPolicyClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})

	if err := adapter.UpdateBlocklist(t.Context(), "session-policy-0001", domain.BlocklistUpdatePayload{
		To: "lid:987654321", Action: "BLOCK",
	}); err != nil {
		t.Fatalf("block LID: %v", err)
	}
	if client.blockJID.User != "5511999991234" || client.blockJID.Server != types.DefaultUserServer ||
		client.blockAction != events.BlocklistChangeActionBlock {
		t.Fatalf("blocklist did not resolve to PN: jid=%s action=%s", client.blockJID, client.blockAction)
	}
	if err := adapter.UpdatePrivacy(t.Context(), "session-policy-0001", domain.PrivacyUpdatePayload{
		Name: "last", Value: "contacts",
	}); err != nil || client.privacyName != types.PrivacySettingTypeLastSeen ||
		client.privacyValue != types.PrivacySettingContacts {
		t.Fatalf("allowed privacy update failed: name=%q value=%q err=%v",
			client.privacyName, client.privacyValue, err)
	}
	for _, payload := range []domain.PrivacyUpdatePayload{
		{Name: "status", Value: "all"},
		{Name: "groupadd", Value: "contacts"},
		{Name: "messages", Value: "all"},
		{Name: "last", Value: "known"},
	} {
		if err := adapter.UpdatePrivacy(t.Context(), "session-policy-0001", payload); err == nil {
			t.Fatalf("out-of-scope or stale-cache privacy pair was accepted: %+v", payload)
		}
	}
}

func TestAccountPolicyBuildsOnlyAllowlistedDirectChatPatches(t *testing.T) {
	t.Parallel()
	client := &fakeAccountPolicyClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	base := domain.ChatStatePayload{
		To: "+5511999991234", Value: true, TargetMessageID: "target-message-0001",
		Sender: "+5511999991234", Timestamp: 1_785_000_000,
	}
	tests := []struct {
		action string
		index  string
	}{
		{action: "ARCHIVE", index: appstate.IndexArchive},
		{action: "MUTE", index: appstate.IndexMute},
		{action: "PIN", index: appstate.IndexPin},
		{action: "STAR", index: appstate.IndexStar},
		{action: "MARK_READ", index: appstate.IndexMarkChatAsRead},
		{action: "DELETE_CHAT", index: appstate.IndexDeleteChat},
	}
	for _, test := range tests {
		t.Run(test.action, func(t *testing.T) {
			payload := base
			payload.Action = test.action
			payload.DurationSeconds = 60
			if err := adapter.UpdateChatState(t.Context(), "session-policy-0001", payload); err != nil {
				t.Fatalf("update chat state: %v", err)
			}
			if len(client.patch.Mutations) == 0 || client.patch.Mutations[0].Index[0] != test.index {
				t.Fatalf("unexpected patch for %s: %+v", test.action, client.patch)
			}
		})
	}
	if err := adapter.UpdateChatState(t.Context(), "session-policy-0001", domain.ChatStatePayload{
		To: "12345@g.us", Action: "ARCHIVE", Value: true,
	}); err == nil {
		t.Fatal("group chat entered app-state patch builder")
	}
}

func TestAccountPolicySyncsKnownCollectionsAndMarksOnlyAccountSyncClean(t *testing.T) {
	t.Parallel()
	client := &fakeAccountPolicyClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})

	if err := adapter.UpdateChatState(t.Context(), "session-policy-0001", domain.ChatStatePayload{
		Action: "SYNC",
	}); err != nil || len(client.fetched) != len(appstate.AllPatchNames) {
		t.Fatalf("app-state sync failed: fetched=%v err=%v", client.fetched, err)
	}
	if err := adapter.UpdateChatState(t.Context(), "session-policy-0001", domain.ChatStatePayload{
		Action: "MARK_CLEAN", Timestamp: 1_785_000_000,
	}); err != nil || client.cleanType != "account_sync" || client.cleanAt.Unix() != 1_785_000_000 {
		t.Fatalf("mark-not-dirty failed: type=%q at=%v err=%v", client.cleanType, client.cleanAt, err)
	}
	if err := adapter.SetDefaultDisappearing(t.Context(), "session-policy-0001", domain.DefaultDisappearingPayload{
		TimerSeconds: uint32((7 * 24 * time.Hour).Seconds()),
	}); err != nil || client.defaultTimer != 7*24*time.Hour {
		t.Fatalf("default disappearing failed: timer=%s err=%v", client.defaultTimer, err)
	}
	if err := adapter.SetDefaultDisappearing(t.Context(), "session-policy-0001", domain.DefaultDisappearingPayload{
		TimerSeconds: 123,
	}); err == nil {
		t.Fatal("unsupported default timer was accepted")
	}
}
