package protocol

import (
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow/proto/waCommon"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waWeb"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

func TestProtocolControlsNeverProjectAsConversationMessages(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 1<<20)
	peer := types.NewJID("5511999991234", types.DefaultUserServer)
	controls := []*events.Message{
		{
			Info:    types.MessageInfo{MessageSource: types.MessageSource{Chat: peer, Sender: peer}, ID: "protocol-control-known", Timestamp: time.Now()},
			Message: &waE2E.Message{ProtocolMessage: &waE2E.ProtocolMessage{Type: waE2E.ProtocolMessage_Type(5).Enum()}},
		},
		{
			Info:    types.MessageInfo{MessageSource: types.MessageSource{Chat: peer, Sender: peer}, ID: "protocol-control-unknown", Timestamp: time.Now()},
			Message: &waE2E.Message{ProtocolMessage: &waE2E.ProtocolMessage{Type: waE2E.ProtocolMessage_Type(999).Enum()}},
		},
	}
	for _, control := range controls {
		bridge.handle(t.Context(), "session-protocol-control", nil, control)
	}
	if pending := pendingEvents(t, persistence); len(pending) != 0 {
		t.Fatalf("protocol control entered the live ledger: %+v", pending)
	}
	if bridge.ProtocolControlRejectedCount() != uint64(len(controls)) {
		t.Fatalf("protocol control metric is not aggregate-safe: %d", bridge.ProtocolControlRejectedCount())
	}

	historyType := waHistorySync.HistorySync_RECENT
	bridge.handle(t.Context(), "session-protocol-control", &fakeEventBridgeClient{}, &events.HistorySync{Data: &waHistorySync.HistorySync{
		SyncType: &historyType,
		Conversations: []*waHistorySync.Conversation{{ID: proto.String(peer.String()), Messages: []*waHistorySync.HistorySyncMsg{
			{Message: &waWeb.WebMessageInfo{Key: &waCommon.MessageKey{ID: proto.String("protocol-control-history-known")}, Message: controls[0].Message}},
			{Message: &waWeb.WebMessageInfo{Key: &waCommon.MessageKey{ID: proto.String("protocol-control-history-unknown")}, Message: controls[1].Message}},
		}}},
	}})
	if bridge.ProtocolControlRejectedCount() != uint64(len(controls)*2) {
		t.Fatalf("historical protocol controls were not counted: %d", bridge.ProtocolControlRejectedCount())
	}
	for _, pending := range pendingEvents(t, persistence) {
		if pending.Event.Type == domain.EventMessageReceived || string(pending.Event.Payload) == "" {
			t.Fatalf("historical protocol control became conversational data: %s", pending.Event.Payload)
		}
		for _, forbidden := range []string{"protocol-control-history", peer.String(), "999"} {
			if strings.Contains(string(pending.Event.Payload), forbidden) {
				t.Fatalf("protocol observability leaked %q", forbidden)
			}
		}
	}
}

func TestHistoryMediaProjectsRetryAvailableOnlyAfterDescriptorPersistence(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 1<<20)
	peer := types.NewJID("5511999991234", types.DefaultUserServer)
	historyType := waHistorySync.HistorySync_RECENT
	bridge.handle(t.Context(), "session-history-media", &fakeEventBridgeClient{}, &events.HistorySync{Data: &waHistorySync.HistorySync{
		SyncType: &historyType,
		Conversations: []*waHistorySync.Conversation{{ID: proto.String(peer.String()), Messages: []*waHistorySync.HistorySyncMsg{
			{Message: &waWeb.WebMessageInfo{Key: &waCommon.MessageKey{ID: proto.String("history-media-inbound")}, Message: &waE2E.Message{ImageMessage: &waE2E.ImageMessage{MediaKey: bytesOf(32, 0x77)}}}},
			{Message: &waWeb.WebMessageInfo{Key: &waCommon.MessageKey{ID: proto.String("history-media-outbound"), FromMe: proto.Bool(true)}, Message: &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{MediaKey: bytesOf(32, 0x78)}}}},
		}}},
	}})
	for _, pending := range pendingEvents(t, persistence) {
		if pending.Event.Type != domain.EventHistorySynced {
			continue
		}
		var payload struct {
			Messages []map[string]any `json:"messages"`
		}
		if err := json.Unmarshal(pending.Event.Payload, &payload); err != nil || len(payload.Messages) != 2 || payload.Messages[0]["media_state"] != "RETRY_AVAILABLE" || payload.Messages[1]["media_state"] != "RETRY_AVAILABLE" || payload.Messages[1]["direction"] != "OUTBOUND" {
			t.Fatalf("historical media retry availability was not projected: %s", pending.Event.Payload)
		}
		return
	}
	t.Fatal("history media batch was not emitted")
}

func TestLiveMediaWithoutSpoolProjectsExplicitUnavailability(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 1<<20)
	peer := types.NewJID("5511999991234", types.DefaultUserServer)
	bridge.handle(t.Context(), "session-live-media", nil, &events.Message{
		Info:    types.MessageInfo{MessageSource: types.MessageSource{Chat: peer, Sender: peer}, ID: "live-media-no-spool", Timestamp: time.Now()},
		Message: &waE2E.Message{ImageMessage: &waE2E.ImageMessage{MediaKey: bytesOf(32, 0x79)}},
	})
	for _, pending := range pendingEvents(t, persistence) {
		if pending.Event.Type != domain.EventMessageReceived {
			continue
		}
		var payload map[string]any
		if err := json.Unmarshal(pending.Event.Payload, &payload); err != nil || payload["media_state"] != "UNAVAILABLE" || payload["media_error_code"] != "MEDIA_SPOOL_UNAVAILABLE" {
			t.Fatalf("live media availability was ambiguous: %s", pending.Event.Payload)
		}
		return
	}
	t.Fatal("live media event was not emitted")
}
