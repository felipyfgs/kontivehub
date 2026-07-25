package protocol

import (
	"context"
	"testing"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow/types"
)

type fakePresenceClient struct {
	fakeClient
	presence   types.Presence
	subscribed types.JID
	chatState  types.ChatPresence
	chatMedia  types.ChatPresenceMedia
	forced     bool
}

func (c *fakePresenceClient) SendPresence(_ context.Context, presence types.Presence) error {
	c.presence = presence
	return nil
}

func (c *fakePresenceClient) SubscribePresence(_ context.Context, jid types.JID) error {
	c.subscribed = jid
	return nil
}

func (c *fakePresenceClient) SendChatPresence(
	_ context.Context,
	_ types.JID,
	presence types.ChatPresence,
	media types.ChatPresenceMedia,
) error {
	c.chatState, c.chatMedia = presence, media
	return nil
}

func (c *fakePresenceClient) SetForceActiveDeliveryReceipts(active bool) {
	c.forced = active
}

func TestPresenceAdapterUsesClosedEnumsAndDirectRecipient(t *testing.T) {
	t.Parallel()
	client := &fakePresenceClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	force := true

	if err := adapter.SetPresence(t.Context(), "session-presence-0001", domain.PresencePayload{
		Presence: "AVAILABLE", ForceActiveDeliveryReceipts: &force,
	}); err != nil || client.presence != types.PresenceAvailable || !client.forced {
		t.Fatalf("global presence failed: presence=%q forced=%v err=%v", client.presence, client.forced, err)
	}
	if err := adapter.SubscribeContactPresence(t.Context(), "session-presence-0001", domain.ContactPresencePayload{
		To: "+5511999991234",
	}); err != nil || client.subscribed.User != "5511999991234" || client.subscribed.Server != types.DefaultUserServer {
		t.Fatalf("presence subscription failed: jid=%s err=%v", client.subscribed, err)
	}
	if err := adapter.SetChatPresence(t.Context(), "session-presence-0001", domain.ChatPresencePayload{
		To: "+5511999991234", Presence: "RECORDING", Media: "AUDIO",
	}); err != nil || client.chatState != types.ChatPresenceComposing || client.chatMedia != types.ChatPresenceMediaAudio {
		t.Fatalf("chat presence failed: state=%q media=%q err=%v", client.chatState, client.chatMedia, err)
	}
}

func TestPresenceAdapterRejectsUnknownEnums(t *testing.T) {
	t.Parallel()
	client := &fakePresenceClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})

	if err := adapter.SetPresence(t.Context(), "session-presence-0001", domain.PresencePayload{
		Presence: "INVISIBLE",
	}); err == nil {
		t.Fatal("unknown global presence was accepted")
	}
	if err := adapter.SetChatPresence(t.Context(), "session-presence-0001", domain.ChatPresencePayload{
		To: "+5511999991234", Presence: "TYPING_RAW",
	}); err == nil {
		t.Fatal("unknown chat presence was accepted")
	}
}
