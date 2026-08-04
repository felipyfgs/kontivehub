package protocol

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/spool"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow"
	waBinary "go.mau.fi/whatsmeow/binary"
	"go.mau.fi/whatsmeow/proto/waCommon"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waMmsRetry"
	"go.mau.fi/whatsmeow/proto/waSyncAction"
	"go.mau.fi/whatsmeow/proto/waWeb"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"go.mau.fi/whatsmeow/util/gcmutil"
	"go.mau.fi/whatsmeow/util/hkdfutil"
	"google.golang.org/protobuf/proto"
)

type fakeEventBridgeClient struct {
	pollVote        *waE2E.PollVoteMessage
	secretMessage   *waE2E.Message
	pollErr         error
	secretErr       error
	parseErr        error
	pollCalls       int
	secretCalls     int
	parseCalls      int
	downloadPayload []byte
}

func TestEventBridgeForwardsOnlySessionLifecycleSignals(t *testing.T) {
	t.Parallel()
	bridge := NewEventBridge(store.NewMemory(), nil, 20<<20)
	type observed struct {
		sessionID string
		event     domain.SessionLifecycleEvent
	}
	observedEvents := make([]observed, 0, 3)
	bridge.SetLifecycleObserver(func(sessionID string, event domain.SessionLifecycleEvent) {
		observedEvents = append(observedEvents, observed{sessionID: sessionID, event: event})
	})

	bridge.handle(t.Context(), "session-lifecycle-0001", nil, &events.Disconnected{})
	bridge.handle(t.Context(), "session-lifecycle-0001", nil, &events.ManualLoginReconnect{})
	bridge.handle(t.Context(), "session-lifecycle-0001", nil, &events.LoggedOut{})

	want := []domain.SessionLifecycleEvent{
		domain.SessionLifecycleDisconnected,
		domain.SessionLifecycleManualLoginReconnect,
		domain.SessionLifecycleLoggedOut,
	}
	if len(observedEvents) != len(want) {
		t.Fatalf("unexpected lifecycle signal count: %+v", observedEvents)
	}
	for index, event := range want {
		if observedEvents[index].sessionID != "session-lifecycle-0001" || observedEvents[index].event != event {
			t.Fatalf("unexpected lifecycle signal at %d: %+v", index, observedEvents[index])
		}
	}
}

func TestEventBridgeClassifiesDisconnectedFromDesiredState(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	for _, sessionState := range []domain.Session{
		{SessionID: "session-unexpected-drop", Status: domain.SessionConnected, DesiredConnected: true},
		{SessionID: "session-explicit-stop", Status: domain.SessionDisconnected, DesiredConnected: false},
	} {
		if err := persistence.UpsertSession(t.Context(), sessionState); err != nil {
			t.Fatalf("seed session: %v", err)
		}
	}
	bridge := NewEventBridge(persistence, nil, 20<<20)
	bridge.handle(t.Context(), "session-unexpected-drop", nil, &events.Disconnected{})
	bridge.handle(t.Context(), "session-explicit-stop", nil, &events.Disconnected{})
	pending := pendingEvents(t, persistence)
	statuses := make(map[string]string)
	for _, item := range pending {
		if item.Event.Type != domain.EventSessionStatusChanged {
			continue
		}
		var payload map[string]string
		if err := json.Unmarshal(item.Event.Payload, &payload); err != nil {
			t.Fatalf("decode session status: %v", err)
		}
		statuses[item.Event.SessionID] = payload["status"]
	}
	if statuses["session-unexpected-drop"] != "CONNECTING" || statuses["session-explicit-stop"] != "DISCONNECTED" {
		t.Fatalf("disconnect classification ignored desired state: %+v", statuses)
	}
}

type failingEventStore struct {
	*store.Memory
}

func (s *failingEventStore) AppendEvent(context.Context, domain.Event) (bool, error) {
	return false, errors.New("persistence-secret-detail")
}

type recordingEventStore struct {
	*store.Memory
	order []domain.EventType
}

type pairSuccessRecorder struct {
	calls       int
	forgetCalls int
	err         error
}

func (r *pairSuccessRecorder) RecordDevice(context.Context, string) error {
	r.calls++
	return r.err
}

func (r *pairSuccessRecorder) ForgetSession(context.Context, string) error {
	r.forgetCalls++
	return nil
}

func TestEventBridgePersistsDeviceBeforePairSuccessProjection(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	recorder := &pairSuccessRecorder{}
	bridge := NewEventBridge(persistence, nil, 20<<20)
	bridge.SetDeviceRecorder(recorder)
	if !bridge.HandleWithSuccess("session-pair-success-0001", nil, &events.PairSuccess{}) {
		t.Fatal("successful device mapping was reported as an event failure")
	}
	if recorder.calls != 1 {
		t.Fatalf("device mapping calls=%d, want 1", recorder.calls)
	}
	pending := pendingEvents(t, persistence)
	typesSeen := make(map[domain.EventType]bool, len(pending))
	for _, item := range pending {
		typesSeen[item.Event.Type] = true
	}
	if len(pending) != 2 || !typesSeen[domain.EventSessionStatusChanged] ||
		!typesSeen[domain.EventPairingUpdated] {
		t.Fatalf("pair success projection changed: %+v", pending)
	}
}

func TestEventBridgeRejectsConnectedProjectionWhenDeviceMappingFails(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	recorder := &pairSuccessRecorder{err: errors.New("sensitive database detail")}
	bridge := NewEventBridge(persistence, nil, 20<<20)
	bridge.SetDeviceRecorder(recorder)
	if bridge.HandleWithSuccess("session-pair-failure-0001", nil, &events.PairSuccess{}) {
		t.Fatal("mapping failure was acknowledged as successful")
	}
	if recorder.forgetCalls != 1 {
		t.Fatalf("unmapped paired device cleanup calls=%d, want 1", recorder.forgetCalls)
	}
	pending := pendingEvents(t, persistence)
	for _, item := range pending {
		if item.Event.Type != domain.EventSessionStatusChanged {
			continue
		}
		var payload map[string]any
		if err := json.Unmarshal(item.Event.Payload, &payload); err != nil {
			t.Fatalf("decode status: %v", err)
		}
		if payload["status"] == string(domain.SessionConnected) {
			t.Fatal("CONNECTED was projected without a durable device mapping")
		}
	}
}

func (s *recordingEventStore) AppendEvent(ctx context.Context, event domain.Event) (bool, error) {
	duplicate, err := s.Memory.AppendEvent(ctx, event)
	if err == nil && !duplicate {
		s.order = append(s.order, event.Type)
	}
	return duplicate, err
}

func (f *fakeEventBridgeClient) DecryptPollVote(
	_ context.Context,
	_ *events.Message,
) (*waE2E.PollVoteMessage, error) {
	f.pollCalls++
	return f.pollVote, f.pollErr
}

func (f *fakeEventBridgeClient) DecryptSecretEncryptedMessage(
	_ context.Context,
	_ *events.Message,
) (*waE2E.Message, error) {
	f.secretCalls++
	return f.secretMessage, f.secretErr
}

func (f *fakeEventBridgeClient) ParseWebMessage(chat types.JID, webMessage *waWeb.WebMessageInfo) (*events.Message, error) {
	f.parseCalls++
	if f.parseErr != nil {
		return nil, f.parseErr
	}
	return &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat: chat, Sender: chat, IsFromMe: webMessage.GetKey().GetFromMe(),
			},
			ID: webMessage.GetKey().GetID(), Timestamp: time.Unix(int64(webMessage.GetMessageTimestamp()), 0),
		},
		Message: webMessage.GetMessage(),
	}, nil
}

func (f *fakeEventBridgeClient) DownloadToFile(
	_ context.Context,
	_ whatsmeow.DownloadableMessage,
	file whatsmeow.File,
) error {
	_, err := file.Write(f.downloadPayload)
	return err
}

func TestEventBridgePersistsOneToOneInboundAndIgnoresGroups(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	timestamp := time.Now().UTC()
	message := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:   types.NewJID("5511999991234", types.DefaultUserServer),
				Sender: types.NewJID("5511999991234", types.DefaultUserServer),
			},
			ID: "provider-inbound-0001", Timestamp: timestamp,
		},
		Message: &waE2E.Message{Conversation: proto.String("Olá do cliente")},
	}
	bridge.Handle("session-bridge-0001", nil, message)

	pending, err := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
	if err != nil || len(pending) != 1 {
		t.Fatalf("inbound event not persisted: events=%d err=%v", len(pending), err)
	}
	var payload map[string]any
	if err := json.Unmarshal(pending[0].Event.Payload, &payload); err != nil {
		t.Fatalf("decode payload: %v", err)
	}
	if payload["from"] != "+5511999991234" || payload["text"] != "Olá do cliente" || payload["kind"] != "TEXT" {
		t.Fatalf("unexpected inbound payload: %+v", payload)
	}

	message.Info.ID = "provider-group-0001"
	message.Info.IsGroup = true
	message.Info.Chat = types.NewJID("12345", types.GroupServer)
	bridge.Handle("session-bridge-0001", nil, message)
	metrics, _ := persistence.Metrics(t.Context())
	if metrics.PendingEvents != 1 {
		t.Fatalf("group event entered one-to-one ledger: %+v", metrics)
	}
	if bridge.RejectedScopeCount() != 1 {
		t.Fatalf("group event did not increment sanitized rejection metric: %d", bridge.RejectedScopeCount())
	}
}

func TestEventBridgePersistsLiveOutboundFromPairedDevice(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	timestamp := time.Now().UTC()
	bridge.Handle("session-bridge-outbound-0001", nil, &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     contact,
				Sender:   contact,
				IsFromMe: true,
			},
			ID: "provider-live-outbound-0001", Timestamp: timestamp,
		},
		Message: &waE2E.Message{Conversation: proto.String("Enviado no celular")},
	})

	pending, err := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
	if err != nil || len(pending) != 1 {
		t.Fatalf("live outbound event not persisted: events=%d err=%v", len(pending), err)
	}
	if pending[0].Event.Type != domain.EventMessageReceived {
		t.Fatalf("unexpected event type: %s", pending[0].Event.Type)
	}
	var payload map[string]any
	if err := json.Unmarshal(pending[0].Event.Payload, &payload); err != nil {
		t.Fatalf("decode payload: %v", err)
	}
	if payload["from"] != "+5511999991234" ||
		payload["text"] != "Enviado no celular" ||
		payload["direction"] != "OUTBOUND" ||
		payload["provider_message_id"] != "provider-live-outbound-0001" ||
		payload["history"] != nil {
		t.Fatalf("unexpected live outbound payload: %+v", payload)
	}
}

func TestEventBridgeSuccessStatusReflectsDurableAppend(t *testing.T) {
	t.Parallel()
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	message := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{Chat: contact, Sender: contact},
			ID:            "provider-success-status", Timestamp: time.Now(),
		},
		Message: &waE2E.Message{Conversation: proto.String("persistir")},
	}
	working := NewEventBridge(store.NewMemory(), nil, 20<<20)
	if !working.HandleWithSuccess("session-handler-success", nil, message) {
		t.Fatal("successful durable append was reported as handler failure")
	}
	failing := NewEventBridge(&failingEventStore{Memory: store.NewMemory()}, nil, 20<<20)
	if failing.HandleWithSuccess("session-handler-failure", nil, message) {
		t.Fatal("failed durable append was acknowledged to whatsmeow")
	}
}

func TestEventBridgeRejectsEveryNonIndividualScopeBeforeLedger(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	tests := []struct {
		name    string
		chat    types.JID
		isGroup bool
	}{
		{name: "group", chat: types.NewJID("120363000000000000", types.GroupServer), isGroup: true},
		{name: "community", chat: types.NewJID("120363000000000001", types.GroupServer), isGroup: true},
		{name: "newsletter", chat: types.NewJID("120363000000000000", types.NewsletterServer)},
		{name: "broadcast", chat: types.NewJID("123456789", types.BroadcastServer)},
		{name: "status", chat: types.StatusBroadcastJID},
		{name: "unknown", chat: types.NewJID("123456789", "unknown.example")},
	}
	for index, test := range tests {
		message := &events.Message{
			Info: types.MessageInfo{
				MessageSource: types.MessageSource{
					Chat: test.chat, Sender: types.NewJID("5511999991234", types.DefaultUserServer),
					IsGroup: test.isGroup,
				},
				ID: "provider-rejected-000" + string(rune('1'+index)), Timestamp: time.Now().UTC(),
			},
			Message: &waE2E.Message{Conversation: proto.String("must not persist")},
		}
		bridge.Handle("session-bridge-scope", nil, message)
	}

	metrics, err := persistence.Metrics(t.Context())
	if err != nil || metrics.PendingEvents != 0 {
		t.Fatalf("rejected events reached ledger: metrics=%+v err=%v", metrics, err)
	}
	if bridge.RejectedScopeCount() != uint64(len(tests)) {
		t.Fatalf("unexpected sanitized rejection count: got=%d want=%d", bridge.RejectedScopeCount(), len(tests))
	}
}

func TestEventBridgeMapsReceiptsWithoutStatusRegressionLogic(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	bridge.Handle("session-bridge-0002", nil, &events.Receipt{
		MessageSource: types.MessageSource{
			Chat: types.NewJID("5511999991234", types.DefaultUserServer),
		},
		MessageIDs: []types.MessageID{"provider-outbound-0001"},
		Timestamp:  time.Now().UTC(),
		Type:       types.ReceiptTypeRead,
	})
	pending, err := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
	if err != nil || len(pending) != 1 {
		t.Fatalf("receipt event not persisted: events=%d err=%v", len(pending), err)
	}
	var payload map[string]string
	_ = json.Unmarshal(pending[0].Event.Payload, &payload)
	if payload["status"] != "READ" || payload["provider_message_id"] != "provider-outbound-0001" {
		t.Fatalf("unexpected receipt payload: %+v", payload)
	}
}

func TestEventBridgeIgnoresSelfReadAndPlayedReceipts(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)

	for _, receiptType := range []types.ReceiptType{
		types.ReceiptTypeReadSelf,
		types.ReceiptTypePlayedSelf,
	} {
		bridge.Handle("session-self-receipt", nil, &events.Receipt{
			MessageSource: types.MessageSource{
				Chat: types.NewJID("5511999991234", types.DefaultUserServer),
			},
			MessageIDs: []types.MessageID{"provider-message-self-0001"},
			Timestamp:  time.Now(),
			Type:       receiptType,
		})
	}

	if pending := pendingEvents(t, persistence); len(pending) != 0 {
		t.Fatalf("self receipts must not project remote message status: %+v", pending)
	}
}

func TestEventBridgeProjectsPartialAndFullSyncContactClears(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	now := time.Now().UTC()

	bridge.handle(t.Context(), "session-contact-clears", nil, &events.Contact{
		JID: contact, Timestamp: now,
		Action: &waSyncAction.ContactAction{FullName: proto.String(""), FirstName: proto.String("Maria")},
	})
	bridge.handle(t.Context(), "session-contact-clears", nil, &events.Contact{
		JID: contact, Timestamp: now.Add(time.Second), FromFullSync: true,
		Action: &waSyncAction.ContactAction{FullName: proto.String("Maria da Silva"), FirstName: proto.String("")},
	})

	profiles := contactProfilePayloads(t, pendingEvents(t, persistence))
	if len(profiles) != 2 {
		t.Fatalf("expected two contact profile events, got=%d", len(profiles))
	}
	var partial, fullSync map[string]any
	for _, profile := range profiles {
		if profile["from_full_sync"] == true {
			fullSync = profile
		} else {
			partial = profile
		}
	}
	if partial == nil || fullSync == nil {
		t.Fatalf("contact events lost partial/full-sync provenance: %+v", profiles)
	}
	if partial["address_book_first_name"] != "Maria" || !containsString(partial["cleared_fields"], "address_book_full_name") {
		t.Fatalf("partial contact clear lost independent fields: %+v", partial)
	}
	if partial["from_full_sync"] != false {
		t.Fatalf("partial contact must preserve its non-full-sync origin: %+v", partial)
	}
	if fullSync["address_book_full_name"] != "Maria da Silva" || fullSync["from_full_sync"] != true ||
		!containsString(fullSync["cleared_fields"], "address_book_first_name") {
		t.Fatalf("full-sync contact clear lost source or fields: %+v", fullSync)
	}
}

func TestEventBridgePushNameLIDUsesOnlyValidPNAlternateEvidence(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	lid := types.NewJID("987654321", types.HiddenUserServer)

	bridge.handle(t.Context(), "session-push-lid", nil, &events.PushName{
		JID: lid, JIDAlt: types.NewJID("5511999991234", types.DefaultUserServer), NewPushName: "Maria LID",
	})
	bridge.handle(t.Context(), "session-push-lid", nil, &events.PushName{
		JID: lid, JIDAlt: types.NewJID("120363000000000000", types.GroupServer), NewPushName: "Maria sem PN",
	})

	profiles := contactProfilePayloads(t, pendingEvents(t, persistence))
	if len(profiles) != 2 {
		t.Fatalf("expected two LID push-name events, got=%d", len(profiles))
	}
	var valid, invalid map[string]any
	for _, profile := range profiles {
		if profile["push_name"] == "Maria LID" {
			valid = profile
		} else if profile["push_name"] == "Maria sem PN" {
			invalid = profile
		}
	}
	if valid == nil || invalid == nil {
		t.Fatalf("push-name events lost their independent payloads: %+v", profiles)
	}
	if valid["user"] != "lid:987654321" || valid["push_name"] != "Maria LID" {
		t.Fatalf("LID push-name identity changed: %+v", valid)
	}
	evidence, ok := valid["source_identity"].(map[string]any)
	if !ok || evidence["alternate"] != "+5511999991234" || evidence["alternate_kind"] != "PN" {
		t.Fatalf("valid PN alternate evidence missing: %+v", valid)
	}
	if _, ok := invalid["source_identity"]; ok {
		t.Fatalf("invalid alternate must not infer a PN: %+v", invalid)
	}
}

func TestEventBridgeKeepsPlayedDistinctAndReceiptsIdempotentOutOfOrder(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	source := types.MessageSource{Chat: types.NewJID("5511999991234", types.DefaultUserServer)}
	now := time.Now().UTC()
	for _, receiptType := range []types.ReceiptType{
		types.ReceiptTypePlayed, types.ReceiptTypeRead, types.ReceiptTypeDelivered,
		types.ReceiptTypePlayed,
	} {
		bridge.Handle("session-receipt-played", nil, &events.Receipt{
			MessageSource: source, MessageIDs: []types.MessageID{"provider-audio-played"},
			Timestamp: now, Type: receiptType,
		})
	}
	pending := pendingEvents(t, persistence)
	statuses := make(map[string]int)
	for _, item := range pending {
		if item.Event.Type != domain.EventMessageStatusChanged {
			continue
		}
		payload := decodeEventPayload(t, item.Event.Payload)
		statuses[payload["status"].(string)]++
	}
	if len(pending) != 3 || statuses["PLAYED"] != 1 || statuses["READ"] != 1 ||
		statuses["DELIVERED"] != 1 {
		t.Fatalf("receipt facts regressed or duplicated: events=%d statuses=%+v", len(pending), statuses)
	}
}

func TestEventBridgeMapsRetryAndServerErrorReceiptsToUnknownWithDistinctCodes(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	source := types.MessageSource{Chat: types.NewJID("5511999991234", types.DefaultUserServer)}
	for _, receiptType := range []types.ReceiptType{
		types.ReceiptTypeRetry, types.ReceiptTypeServerError,
	} {
		bridge.Handle("session-receipt-operational", nil, &events.Receipt{
			MessageSource: source, MessageIDs: []types.MessageID{"provider-receipt-operational"},
			Timestamp: time.Now().UTC(), Type: receiptType,
		})
	}

	pending := pendingEvents(t, persistence)
	codes := make(map[string]int)
	for _, item := range pending {
		payload := decodeEventPayload(t, item.Event.Payload)
		if payload["status"] != "UNKNOWN" {
			t.Fatalf("operational receipt escaped canonical status machine: %+v", payload)
		}
		code, ok := payload["error_code"].(string)
		if !ok || code == "" {
			t.Fatalf("operational receipt omitted sanitized error code: %+v", payload)
		}
		codes[code]++
	}
	if len(pending) != 2 || codes["WHATSAPP_RECEIPT_RETRY"] != 1 ||
		codes["WHATSAPP_RECEIPT_SERVER_ERROR"] != 1 {
		t.Fatalf("operational receipts were conflated or deduplicated incorrectly: %+v", codes)
	}
}

func TestEventBridgeDecryptsPollVoteAndSecretEditBeforeProjection(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	selectedHash := make([]byte, 32)
	for index := range selectedHash {
		selectedHash[index] = byte(index + 1)
	}
	client := &fakeEventBridgeClient{
		pollVote:      &waE2E.PollVoteMessage{SelectedOptions: [][]byte{selectedHash, []byte("invalid-short")}},
		secretMessage: &waE2E.Message{Conversation: proto.String("texto editado")},
	}
	source := types.MessageSource{
		Chat:   types.NewJID("5511999991234", types.DefaultUserServer),
		Sender: types.NewJID("5511999991234", types.DefaultUserServer),
	}
	bridge.handle(t.Context(), "session-decrypt-0001", client, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-vote-0001", Timestamp: time.Now()},
		Message: &waE2E.Message{PollUpdateMessage: &waE2E.PollUpdateMessage{
			PollCreationMessageKey: &waCommon.MessageKey{ID: proto.String("provider-poll-0001")},
			Vote:                   &waE2E.PollEncValue{EncPayload: []byte("ciphertext-poll-secret"), EncIV: []byte("iv-secret")},
		}},
	})
	bridge.handle(t.Context(), "session-decrypt-0001", client, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-edit-envelope-0001", Timestamp: time.Now()},
		Message: &waE2E.Message{SecretEncryptedMessage: &waE2E.SecretEncryptedMessage{
			TargetMessageKey: &waCommon.MessageKey{ID: proto.String("provider-edit-target-0001")},
			EncPayload:       []byte("ciphertext-edit-secret"), EncIV: []byte("edit-iv-secret"),
			RemoteKeyID:   proto.String("remote-key-secret"),
			SecretEncType: waE2E.SecretEncryptedMessage_MESSAGE_EDIT.Enum(),
		}},
	})

	pending := pendingEvents(t, persistence)
	if len(pending) != 2 || client.pollCalls != 1 || client.secretCalls != 1 {
		t.Fatalf("decrypt projection mismatch: events=%d poll=%d secret=%d", len(pending), client.pollCalls, client.secretCalls)
	}
	vote := payloadForAction(t, pending, "POLL_VOTE")
	if vote["target_message_id"] != "provider-poll-0001" {
		t.Fatalf("unexpected poll target: %+v", vote)
	}
	hashes, ok := vote["option_hashes"].([]any)
	if !ok || len(hashes) != 1 || hashes[0] != "0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20" {
		t.Fatalf("unexpected sanitized poll hashes: %+v", vote)
	}
	edit := payloadForAction(t, pending, "EDIT")
	if edit["target_message_id"] != "provider-edit-target-0001" || edit["text"] != "texto editado" {
		t.Fatalf("unexpected decrypted edit: %+v", edit)
	}
	assertPayloadsDoNotContain(t, pending,
		"ciphertext-poll-secret", "iv-secret", "ciphertext-edit-secret", "edit-iv-secret", "remote-key-secret")
}

func TestPeerSourceIdentityUsesOnlyTheRemoteAliasByDirection(t *testing.T) {
	t.Parallel()
	lid := types.NewJID("149865032093945", types.HiddenUserServer)
	sessionPN := types.NewJID("559981769536", types.DefaultUserServer)
	remotePN := types.NewJID("559992032709", types.DefaultUserServer)
	sessionPN.Device = 3
	remotePN.Device = 7

	inbound := types.MessageSource{
		Chat: lid, Sender: lid, SenderAlt: remotePN,
	}
	inboundPeer, err := normalizeMessageSource(inbound)
	if err != nil {
		t.Fatalf("normalize inbound peer: %v", err)
	}
	inboundIdentity := peerSourceIdentity(inbound, inboundPeer)
	if inboundIdentity["primary"] != "lid:149865032093945" ||
		inboundIdentity["alternate"] != "+559992032709" {
		t.Fatalf("inbound remote alias was not projected: %+v", inboundIdentity)
	}

	outbound := types.MessageSource{
		Chat: lid, Sender: lid, SenderAlt: sessionPN, RecipientAlt: remotePN, IsFromMe: true,
	}
	outboundPeer, err := normalizeMessageSource(outbound)
	if err != nil {
		t.Fatalf("normalize outbound peer: %v", err)
	}
	outboundIdentity := peerSourceIdentity(outbound, outboundPeer)
	if outboundIdentity["primary"] != "lid:149865032093945" ||
		outboundIdentity["alternate"] != "+559992032709" {
		t.Fatalf("outbound recipient alias was not projected: %+v", outboundIdentity)
	}
	if outboundIdentity["alternate"] == "+559981769536" {
		t.Fatalf("outbound session PN leaked as peer alias: %+v", outboundIdentity)
	}

	historyPayload := normalizedHistoryMessage(&events.Message{
		Info: types.MessageInfo{
			MessageSource: outbound,
			ID:            "history-lid-alias-0001",
			Timestamp:     time.Now(),
		},
		Message: &waE2E.Message{Conversation: proto.String("histórico")},
	}, outboundPeer)
	historyIdentity, ok := historyPayload["source_identity"].(map[string]any)
	if !ok || historyIdentity["alternate"] != "+559992032709" {
		t.Fatalf("history lost remote alias: %+v", historyPayload)
	}
}

func TestMessageActionCarriesTheSameRemoteSourceIdentity(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	source := types.MessageSource{
		Chat:         types.NewJID("149865032093945", types.HiddenUserServer),
		Sender:       types.NewJID("149865032093945", types.HiddenUserServer),
		SenderAlt:    types.NewJID("559981769536", types.DefaultUserServer),
		RecipientAlt: types.NewJID("559992032709", types.DefaultUserServer),
		IsFromMe:     true,
	}
	bridge.handle(t.Context(), "session-lid-action", nil, &events.Message{
		Info: types.MessageInfo{
			MessageSource: source,
			ID:            "provider-lid-reaction-0001",
			Timestamp:     time.Now(),
		},
		Message: &waE2E.Message{ReactionMessage: &waE2E.ReactionMessage{
			Key:  &waCommon.MessageKey{ID: proto.String("provider-lid-target-0001")},
			Text: proto.String("👍"),
		}},
	})

	action := payloadForAction(t, pendingEvents(t, persistence), "REACTION")
	identity, ok := action["source_identity"].(map[string]any)
	if !ok || identity["primary"] != "lid:149865032093945" ||
		identity["alternate"] != "+559992032709" {
		t.Fatalf("message action lost remote identity: %+v", action)
	}
}

func TestMessageActionsAreDetectedInsideSupportedWrappers(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	source := types.MessageSource{
		Chat:   types.NewJID("5511999991234", types.DefaultUserServer),
		Sender: types.NewJID("5511999991234", types.DefaultUserServer),
	}
	bridge.handle(t.Context(), "session-wrapped-action", nil, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-wrapped-reaction", Timestamp: time.Now()},
		Message: &waE2E.Message{EphemeralMessage: &waE2E.FutureProofMessage{Message: &waE2E.Message{
			ReactionMessage: &waE2E.ReactionMessage{
				Key: &waCommon.MessageKey{ID: proto.String("provider-wrapped-target")}, Text: proto.String("👍"),
			},
		}}},
	})

	action := payloadForAction(t, pendingEvents(t, persistence), "REACTION")
	if action["target_message_id"] != "provider-wrapped-target" || action["emoji"] != "👍" {
		t.Fatalf("wrapped reaction semantics were lost: %+v", action)
	}
}

func TestEventBridgeProjectsMessageKindsActionsAndQuotes(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	source := types.MessageSource{
		Chat:   types.NewJID("5511999991234", types.DefaultUserServer),
		Sender: types.NewJID("5511999991234", types.DefaultUserServer),
	}
	bridge.handle(t.Context(), "session-message-kinds", nil, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-location-0001", Timestamp: time.Now()},
		Message: &waE2E.Message{LocationMessage: &waE2E.LocationMessage{
			DegreesLatitude: proto.Float64(-23.5), DegreesLongitude: proto.Float64(-46.6),
			Name: proto.String("Escritório"), Address: proto.String("Centro"),
			ContextInfo: &waE2E.ContextInfo{
				StanzaID:    proto.String("provider-quote-0001"),
				Participant: proto.String("5511888884321@s.whatsapp.net"),
			},
		}},
	})
	bridge.handle(t.Context(), "session-message-kinds", nil, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-reaction-0001", Timestamp: time.Now()},
		Message: &waE2E.Message{ReactionMessage: &waE2E.ReactionMessage{
			Key: &waCommon.MessageKey{ID: proto.String("provider-target-0001")}, Text: proto.String(""),
		}},
	})
	bridge.handle(t.Context(), "session-message-kinds", nil, &events.Message{
		Info: types.MessageInfo{MessageSource: source, ID: "provider-revoke-envelope", Timestamp: time.Now()},
		Message: &waE2E.Message{ProtocolMessage: &waE2E.ProtocolMessage{
			Type: waE2E.ProtocolMessage_REVOKE.Enum(),
			Key:  &waCommon.MessageKey{ID: proto.String("provider-revoked-0001")},
		}},
	})

	pending := pendingEvents(t, persistence)
	message := payloadForType(t, pending, domain.EventMessageReceived)
	replyTo := message["reply_to"].(map[string]any)
	location := message["location"].(map[string]any)
	if message["kind"] != "LOCATION" || replyTo["provider_message_id"] != "provider-quote-0001" ||
		replyTo["sender"] != "+5511888884321" || location["name"] != "Escritório" {
		t.Fatalf("unexpected location/quote projection: %+v", message)
	}
	reaction := payloadForAction(t, pending, "REACTION")
	if reaction["emoji"] != "" || reaction["target_message_id"] != "provider-target-0001" {
		t.Fatalf("unexpected reaction projection: %+v", reaction)
	}
	revoke := payloadForAction(t, pending, "REVOKE")
	if revoke["target_message_id"] != "provider-revoked-0001" {
		t.Fatalf("unexpected revoke projection: %+v", revoke)
	}
}

func TestNormalizedMessageContentCoversSupportedOneToOneKinds(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name string
		kind string
		msg  *waE2E.Message
	}{
		{name: "text", kind: "TEXT", msg: &waE2E.Message{Conversation: proto.String("texto")}},
		{name: "image", kind: "IMAGE", msg: &waE2E.Message{ImageMessage: &waE2E.ImageMessage{Caption: proto.String("imagem")}}},
		{name: "audio", kind: "AUDIO", msg: &waE2E.Message{AudioMessage: &waE2E.AudioMessage{PTT: proto.Bool(true)}}},
		{name: "video", kind: "VIDEO", msg: &waE2E.Message{VideoMessage: &waE2E.VideoMessage{Caption: proto.String("vídeo")}}},
		{name: "document", kind: "DOCUMENT", msg: &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{FileName: proto.String("arquivo.pdf")}}},
		{name: "sticker", kind: "STICKER", msg: &waE2E.Message{StickerMessage: &waE2E.StickerMessage{IsAnimated: proto.Bool(true)}}},
		{name: "location", kind: "LOCATION", msg: &waE2E.Message{LocationMessage: &waE2E.LocationMessage{DegreesLatitude: proto.Float64(-23.5), DegreesLongitude: proto.Float64(-46.6)}}},
		{name: "contact", kind: "CONTACT", msg: &waE2E.Message{ContactMessage: &waE2E.ContactMessage{DisplayName: proto.String("Contato"), Vcard: proto.String("BEGIN:VCARD\nEND:VCARD")}}},
		{name: "poll", kind: "POLL", msg: &waE2E.Message{PollCreationMessage: &waE2E.PollCreationMessage{
			Name: proto.String("Escolha"), SelectableOptionsCount: proto.Uint32(1),
			Options: []*waE2E.PollCreationMessage_Option{{OptionName: proto.String("A")}, {OptionName: proto.String("B")}},
		}}},
		{name: "interactive", kind: "INTERACTIVE", msg: &waE2E.Message{ButtonsResponseMessage: &waE2E.ButtonsResponseMessage{
			SelectedButtonID: proto.String("row-1"),
		}}},
	}
	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			payload := normalizedMessageContent(test.msg)
			if payload["kind"] != test.kind {
				t.Fatalf("unexpected kind: got=%v want=%s payload=%+v", payload["kind"], test.kind, payload)
			}
			allowed := map[string]bool{
				"kind": true, "provider_type": true, "family": true, "text": true,
				"caption": true, "filename": true, "location": true, "contacts": true,
				"poll": true, "interactive": true, "ptt": true, "gif": true,
				"animated": true, "duration_seconds": true,
			}
			for key := range payload {
				if !allowed[key] {
					t.Fatalf("message kind %s exposed non-allowlisted field %q", test.kind, key)
				}
			}
		})
	}
}

func TestEventBridgeHistorySyncIsOneToOneBoundedAndDeduplicated(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	recording := &recordingEventStore{Memory: persistence}
	bridge := NewEventBridge(recording, nil, 20<<20)
	selectedHash := make([]byte, 32)
	selectedHash[0] = 9
	client := &fakeEventBridgeClient{
		pollVote:      &waE2E.PollVoteMessage{SelectedOptions: [][]byte{selectedHash}},
		secretMessage: &waE2E.Message{Conversation: proto.String("edição histórica")},
	}
	directMessage := &waWeb.WebMessageInfo{
		Key:              &waCommon.MessageKey{ID: proto.String("history-provider-0001"), FromMe: proto.Bool(false)},
		MessageTimestamp: proto.Uint64(uint64(time.Now().Unix())),
		Message: &waE2E.Message{ExtendedTextMessage: &waE2E.ExtendedTextMessage{
			Text:        proto.String("mensagem histórica"),
			ContextInfo: &waE2E.ContextInfo{StanzaID: proto.String("history-quote-0001")},
		}},
	}
	outboundMessage := &waWeb.WebMessageInfo{
		Key:              &waCommon.MessageKey{ID: proto.String("history-provider-outbound"), FromMe: proto.Bool(true)},
		MessageTimestamp: proto.Uint64(uint64(time.Now().Unix())),
		Message:          &waE2E.Message{Conversation: proto.String("resposta histórica")},
	}
	reactionMessage := &waWeb.WebMessageInfo{
		Key:              &waCommon.MessageKey{ID: proto.String("history-reaction-event"), FromMe: proto.Bool(false)},
		MessageTimestamp: proto.Uint64(uint64(time.Now().Unix())),
		Message: &waE2E.Message{ReactionMessage: &waE2E.ReactionMessage{
			Key: &waCommon.MessageKey{ID: proto.String("history-reaction-target")}, Text: proto.String("👍"),
		}},
	}
	pollMessage := &waWeb.WebMessageInfo{
		Key:              &waCommon.MessageKey{ID: proto.String("history-vote-event"), FromMe: proto.Bool(false)},
		MessageTimestamp: proto.Uint64(uint64(time.Now().Unix())),
		Message: &waE2E.Message{PollUpdateMessage: &waE2E.PollUpdateMessage{
			PollCreationMessageKey: &waCommon.MessageKey{ID: proto.String("history-poll-target")},
			Vote:                   &waE2E.PollEncValue{EncPayload: []byte("history-poll-ciphertext-secret")},
		}},
	}
	editMessage := &waWeb.WebMessageInfo{
		Key:              &waCommon.MessageKey{ID: proto.String("history-edit-event"), FromMe: proto.Bool(false)},
		MessageTimestamp: proto.Uint64(uint64(time.Now().Unix())),
		Message: &waE2E.Message{SecretEncryptedMessage: &waE2E.SecretEncryptedMessage{
			TargetMessageKey: &waCommon.MessageKey{ID: proto.String("history-edit-target")},
			EncPayload:       []byte("history-edit-ciphertext-secret"),
			SecretEncType:    waE2E.SecretEncryptedMessage_MESSAGE_EDIT.Enum(),
		}},
	}
	historyType := waHistorySync.HistorySync_RECENT
	bridge.handle(t.Context(), "session-history-0001", client, &events.HistorySync{Data: &waHistorySync.HistorySync{
		SyncType: &historyType, ChunkOrder: proto.Uint32(2), Progress: proto.Uint32(75),
		Conversations: []*waHistorySync.Conversation{
			{ID: proto.String("5511999991234@s.whatsapp.net"), Messages: []*waHistorySync.HistorySyncMsg{
				{Message: directMessage}, {Message: directMessage}, {Message: outboundMessage}, {Message: reactionMessage},
				{Message: pollMessage}, {Message: editMessage},
			}},
			{ID: proto.String("120363000000000000@g.us"), Messages: []*waHistorySync.HistorySyncMsg{{Message: directMessage}}},
		},
	}})

	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventHistorySynced)
	if payload["message_count"] != float64(2) || payload["rejected_count"] != float64(1) || client.parseCalls != 5 {
		t.Fatalf("unexpected bounded history payload: %+v parse_calls=%d", payload, client.parseCalls)
	}
	messages := payload["messages"].([]any)
	first := historyMessageByID(t, messages, "history-provider-0001")
	historyReply := first["reply_to"].(map[string]any)
	if first["provider_message_id"] != "history-provider-0001" || first["text"] != "mensagem histórica" ||
		historyReply["provider_message_id"] != "history-quote-0001" || first["history"] != true || first["direction"] != "INBOUND" {
		t.Fatalf("unexpected normalized history message: %+v", first)
	}
	outbound := historyMessageByID(t, messages, "history-provider-outbound")
	if outbound["history"] != true || outbound["direction"] != "OUTBOUND" {
		t.Fatalf("outbound history direction was not preserved: %+v", outbound)
	}
	if bridge.RejectedScopeCount() != 1 {
		t.Fatalf("history group scope was not rejected: %d", bridge.RejectedScopeCount())
	}
	for _, action := range []string{"REACTION", "POLL_VOTE", "EDIT"} {
		actionPayload := payloadForAction(t, pending, action)
		if actionPayload["history"] != true {
			t.Fatalf("historical action %s lost history marker: %+v", action, actionPayload)
		}
	}
	if client.pollCalls != 1 || client.secretCalls != 1 {
		t.Fatalf("historical encrypted actions were not decrypted: poll=%d secret=%d", client.pollCalls, client.secretCalls)
	}
	if len(recording.order) != 4 || recording.order[0] != domain.EventHistorySynced {
		t.Fatalf("history base batch must precede its actions in the outbox: %+v", recording.order)
	}
	for _, eventType := range recording.order[1:] {
		if eventType != domain.EventMessageActionReceived {
			t.Fatalf("unexpected event after history batch: %+v", recording.order)
		}
	}
	assertPayloadsDoNotContain(t, pending, "history-poll-ciphertext-secret", "history-edit-ciphertext-secret")
}

func TestEventBridgeProjectsEphemeralProfilePrivacyBlocklistAndAppState(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	now := time.Now().UTC()
	bridge.handle(t.Context(), "session-account-events", nil, &events.ChatPresence{
		MessageSource: types.MessageSource{Chat: contact, Sender: contact},
		State:         types.ChatPresenceComposing, Media: types.ChatPresenceMediaAudio,
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Presence{
		From: contact, Unavailable: true, LastSeen: now.Add(-time.Minute),
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.PushName{
		JID: contact, NewPushName: "Cliente Normalizado",
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.BusinessName{
		JID: contact, NewBusinessName: "Empresa Normalizada",
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Contact{
		JID: contact, Timestamp: now,
		Action: &waSyncAction.ContactAction{FullName: proto.String("Contato Completo")},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Picture{
		JID: contact, PictureID: "picture-public-id", Timestamp: now,
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.UserAbout{
		JID: contact, Status: "Atendimento", Timestamp: now,
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.IdentityChange{
		JID: contact, Timestamp: now, Implicit: true,
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.PrivacySettings{
		NewSettings: types.PrivacySettings{
			LastSeen: types.PrivacySettingContacts, Profile: types.PrivacySettingAll,
			ReadReceipts: types.PrivacySettingNone, Online: types.PrivacySettingMatchLastSeen,
			GroupAdd: types.PrivacySettingAll, Status: types.PrivacySettingAll,
			CallAdd: types.PrivacySettingAll, Messages: types.PrivacySettingAll,
		},
		LastSeenChanged: true, ProfileChanged: true,
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Blocklist{
		Action: events.BlocklistActionDefault, DHash: "sensitive-dhash",
		Changes: []events.BlocklistChange{{JID: contact, Action: events.BlocklistChangeActionBlock}},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Mute{
		JID: contact, Timestamp: now,
		Action: &waSyncAction.MuteAction{Muted: proto.Bool(true), MuteEndTimestamp: proto.Int64(now.Add(time.Hour).Unix())},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.DeleteForMe{
		ChatJID: contact, MessageID: "provider-delete-for-me", Timestamp: now,
		Action: &waSyncAction.DeleteMessageForMeAction{DeleteMedia: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Pin{
		JID: contact, Timestamp: now.Add(time.Second), Action: &waSyncAction.PinAction{Pinned: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Star{
		ChatJID: contact, MessageID: "provider-starred", Timestamp: now.Add(2 * time.Second),
		Action: &waSyncAction.StarAction{Starred: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.Archive{
		JID: contact, Timestamp: now.Add(3 * time.Second), Action: &waSyncAction.ArchiveChatAction{Archived: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.MarkChatAsRead{
		JID: contact, Timestamp: now.Add(4 * time.Second), Action: &waSyncAction.MarkChatAsReadAction{Read: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.ClearChat{JID: contact, Timestamp: now.Add(5 * time.Second)})
	bridge.handle(t.Context(), "session-account-events", nil, &events.DeleteChat{JID: contact, Timestamp: now.Add(6 * time.Second)})
	bridge.handle(t.Context(), "session-account-events", nil, &events.LabelAssociationChat{
		JID: contact, Timestamp: now.Add(7 * time.Second), LabelID: "label-clientes",
		Action: &waSyncAction.LabelAssociationAction{Labeled: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.LabelAssociationMessage{
		JID: contact, Timestamp: now.Add(8 * time.Second), LabelID: "label-urgente", MessageID: "provider-labeled",
		Action: &waSyncAction.LabelAssociationAction{Labeled: proto.Bool(true)},
	})
	bridge.handle(t.Context(), "session-account-events", nil, &events.AppStateSyncComplete{Version: 7, Recovery: true})
	bridge.handle(t.Context(), "session-account-events", nil, &events.AppStateSyncError{Error: errors.New("app-state-secret-error")})

	pending := pendingEvents(t, persistence)
	chatPresence := payloadForType(t, pending, domain.EventChatPresenceChanged)
	if chatPresence["ttl_seconds"] != float64(chatPresenceTTLSeconds) || chatPresence["media"] != "AUDIO" ||
		chatPresence["presence"] != "RECORDING" {
		t.Fatalf("unexpected ephemeral chat presence: %+v", chatPresence)
	}
	presence := payloadForType(t, pending, domain.EventPresenceChanged)
	if presence["available"] != false || presence["ttl_seconds"] != float64(contactPresenceTTL) {
		t.Fatalf("unexpected contact presence: %+v", presence)
	}
	privacy := payloadForType(t, pending, domain.EventPrivacyChanged)
	privacySettings := privacy["settings"].([]any)
	if len(privacySettings) != 4 {
		t.Fatalf("unexpected closed privacy projection: %+v", privacy)
	}
	for _, setting := range privacySettings {
		name := setting.(map[string]any)["name"]
		if name == "groupadd" || name == "status" || name == "calladd" {
			t.Fatalf("out-of-scope privacy setting leaked: %+v", privacy)
		}
	}
	blocklist := payloadForType(t, pending, domain.EventBlocklistChanged)
	if blocklist["action"] != "BLOCK" || len(blocklist["users"].([]any)) != 1 {
		t.Fatalf("unexpected blocklist projection: %+v", blocklist)
	}
	for key, expected := range map[string]string{
		"display_name": "Cliente Normalizado", "business_name": "Empresa Normalizada",
		"picture_id": "picture-public-id", "about": "Atendimento",
	} {
		if !profilePayloadContains(t, pending, key, expected) {
			t.Fatalf("profile event %s=%q was not projected", key, expected)
		}
	}
	actions := chatStateActions(t, pending)
	for _, action := range []string{"MUTE", "DELETE_FOR_ME", "PIN", "STAR", "ARCHIVE", "MARK_READ", "CLEAR_CHAT", "DELETE_CHAT", "LABEL_CHAT", "LABEL_MESSAGE"} {
		if !actions[action] {
			t.Fatalf("app-state action %s was not projected: %+v", action, actions)
		}
	}
	assertPayloadsDoNotContain(t, pending, "sensitive-dhash", "app-state-secret-error")
}

func TestEventBridgeOperationalAndRecoveryEventsAreSanitized(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	now := time.Now().UTC()
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.QR{Codes: []string{"qr-secret-value"}})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.PairError{Error: errors.New("pair-token-secret")})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.ConnectFailure{
		Reason: events.ConnectFailureGeneric, Message: "credential-secret-message",
		Raw: &waBinary.Node{Tag: "secret-node", Content: []byte("raw-node-secret")},
	})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.StreamError{
		Code: "bad/code\nunsafe", Raw: &waBinary.Node{Tag: "raw-secret-node"},
	})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.UndecryptableMessage{
		Info:          types.MessageInfo{MessageSource: types.MessageSource{Chat: contact, Sender: contact}, ID: "provider-undec-0001", Timestamp: now},
		IsUnavailable: true, UnavailableType: events.UnavailableTypeViewOnce,
	})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.MediaRetry{
		Ciphertext: []byte("retry-ciphertext-secret"), IV: []byte("retry-iv-secret"),
		Timestamp: now, MessageID: "provider-media-retry", ChatID: contact,
	})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.MediaRetry{
		Ciphertext: []byte("retry-error-ciphertext-secret"), IV: []byte("retry-error-iv-secret"),
		Timestamp: now, MessageID: "provider-media-retry-error", ChatID: contact,
		Error: &events.MediaRetryError{Code: 2},
	})
	bridge.handle(t.Context(), "session-sensitive-events", nil, &events.AppState{
		Index:           []string{"raw-app-state-index-secret"},
		SyncActionValue: &waSyncAction.SyncActionValue{Timestamp: proto.Int64(now.Unix())},
	})

	pending := pendingEvents(t, persistence)
	if len(pending) != 8 {
		t.Fatalf("unexpected sanitized operational event count: %d", len(pending))
	}
	assertPayloadsDoNotContain(t, pending,
		"qr-secret-value", "pair-token-secret", "credential-secret-message", "raw-node-secret",
		"raw-secret-node", "retry-ciphertext-secret", "retry-iv-secret", "retry-error-ciphertext-secret",
		"retry-error-iv-secret", "raw-app-state-index-secret")
	for _, event := range pending {
		assertNoSensitivePayloadKeys(t, event.Event.Payload)
	}
}

func TestMediaRetryRehydratesOriginalMessageIntoEncryptedSpoolAndRedelivers(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	box, err := cryptobox.New(bytesOf(32, 0x73))
	if err != nil {
		t.Fatalf("create spool box: %v", err)
	}
	mediaSpool, err := spool.Open(t.TempDir(), box)
	if err != nil {
		t.Fatalf("open spool: %v", err)
	}
	client := &fakeEventBridgeClient{downloadPayload: []byte("recovered-media-content")}
	bridge := NewEventBridge(persistence, mediaSpool, 1<<20)
	mediaKey := bytesOf(32, 0x41)
	fileDigest := sha256.Sum256(client.downloadPayload)
	original := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
		MediaKey: mediaKey, FileSHA256: fileDigest[:], FileLength: proto.Uint64(uint64(len(client.downloadPayload))),
		Mimetype: proto.String("image/jpeg"), DirectPath: proto.String("/expired-secret-path"),
	}}
	if err := bridge.rememberMediaRetry(
		t.Context(), "session-media-retry", "provider-media-retry", original, types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.NewJID("5511999999999", types.DefaultUserServer),
				Sender:   types.NewJID("5511999999999", types.DefaultUserServer),
				IsFromMe: false,
			},
			ID: "provider-media-retry",
		},
	); err != nil {
		t.Fatalf("remember media retry: %v", err)
	}
	expected, err := persistence.GetMediaRetryState(
		t.Context(), "session-media-retry", "provider-media-retry",
	)
	if err != nil {
		t.Fatalf("get media retry state: %v", err)
	}
	state, claimed, err := persistence.CompareAndBeginMediaRetry(t.Context(), expected, time.Now())
	if err != nil || !claimed || state.Generation != 1 || state.Attempts != 1 {
		t.Fatalf("begin media retry: state=%+v claimed=%t err=%v", state, claimed, err)
	}
	notification := &waMmsRetry.MediaRetryNotification{
		StanzaID:   proto.String("provider-media-retry"),
		DirectPath: proto.String("/new-secret-path"),
		Result:     waMmsRetry.MediaRetryNotification_SUCCESS.Enum(),
	}
	plain, _ := proto.Marshal(notification)
	iv := bytesOf(12, 0x52)
	retryKey := hkdfutil.SHA256(mediaKey, nil, []byte("WhatsApp Media Retry Notification"), 32)
	ciphertext, err := gcmutil.Encrypt(retryKey, iv, plain, []byte("provider-media-retry"))
	if err != nil {
		t.Fatalf("encrypt retry fixture: %v", err)
	}
	event := &events.MediaRetry{
		Ciphertext: ciphertext, IV: iv, Timestamp: time.Now(),
		MessageID: "provider-media-retry",
		ChatID:    types.NewJID("5511999991234", types.DefaultUserServer),
	}
	bridge.handle(t.Context(), "session-media-retry", client, event)

	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventMediaRetryUpdated)
	if payload["status"] != "READY" || payload["generation"] != float64(1) ||
		payload["attempt"] != float64(1) || payload["sha256"] != hex.EncodeToString(fileDigest[:]) ||
		payload["mime_type"] != "image/jpeg" || payload["spool_id"] == "" {
		t.Fatalf("unexpected media retry payload: %+v", payload)
	}
	assertPayloadsDoNotContain(t, pending,
		"/expired-secret-path", "/new-secret-path", string(mediaKey), string(ciphertext), string(iv))
	if count, countErr := mediaSpool.Count(); countErr != nil || count != 1 {
		t.Fatalf("retry spool was not retained before ACK: count=%d err=%v", count, countErr)
	}

	restarted := NewEventBridge(persistence, mediaSpool, 1<<20)
	restarted.handle(t.Context(), "session-media-retry", client, event)
	if count, countErr := mediaSpool.Count(); countErr != nil || count != 1 {
		t.Fatalf("retry redelivery duplicated/lost spool: count=%d err=%v", count, countErr)
	}
}

func TestMediaRetryRejectsDigestMismatchWithoutExposingDescriptor(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	box, _ := cryptobox.New(bytesOf(32, 0x63))
	mediaSpool, _ := spool.Open(t.TempDir(), box)
	bridge := NewEventBridge(persistence, mediaSpool, 1<<20)
	mediaKey := bytesOf(32, 0x31)
	message := &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{
		MediaKey: mediaKey, FileSHA256: bytesOf(32, 0xff), FileLength: proto.Uint64(7),
		Mimetype: proto.String("application/pdf"), FileName: proto.String("../../private.pdf"),
	}}
	if err := bridge.rememberMediaRetry(t.Context(), "session-retry-digest", "provider-retry-digest", message, types.MessageInfo{
		MessageSource: types.MessageSource{
			Chat:     types.NewJID("5511999999999", types.DefaultUserServer),
			Sender:   types.NewJID("5511999999999", types.DefaultUserServer),
			IsFromMe: false,
		},
		ID: "provider-retry-digest",
	}); err != nil {
		t.Fatalf("remember retry descriptor: %v", err)
	}
	expected, err := persistence.GetMediaRetryState(t.Context(), "session-retry-digest", "provider-retry-digest")
	if err != nil {
		t.Fatalf("get digest retry state: %v", err)
	}
	if _, claimed, claimErr := persistence.CompareAndBeginMediaRetry(t.Context(), expected, time.Now()); claimErr != nil || !claimed {
		t.Fatalf("claim digest retry state: claimed=%t err=%v", claimed, claimErr)
	}
	notification := &waMmsRetry.MediaRetryNotification{
		DirectPath: proto.String("/retry/private"), Result: waMmsRetry.MediaRetryNotification_SUCCESS.Enum(),
	}
	plain, _ := proto.Marshal(notification)
	iv := bytesOf(12, 0x42)
	key := hkdfutil.SHA256(mediaKey, nil, []byte("WhatsApp Media Retry Notification"), 32)
	ciphertext, _ := gcmutil.Encrypt(key, iv, plain, []byte("provider-retry-digest"))
	client := &fakeEventBridgeClient{downloadPayload: []byte("invalid")}
	bridge.handle(t.Context(), "session-retry-digest", client, &events.MediaRetry{
		Ciphertext: ciphertext, IV: iv, Timestamp: time.Now(), MessageID: "provider-retry-digest",
		ChatID: types.NewJID("5511999991234", types.DefaultUserServer),
	})
	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventMediaRetryUpdated)
	if payload["status"] != "FAILED" || payload["error_code"] != "MEDIA_RETRY_DIGEST_MISMATCH" {
		t.Fatalf("digest mismatch was not fail-closed: %+v", payload)
	}
	if count, _ := mediaSpool.Count(); count != 0 {
		t.Fatalf("digest-mismatched spool was retained: %d", count)
	}
	assertPayloadsDoNotContain(t, pending, "/retry/private", string(mediaKey), string(ciphertext))

	oversizedID := "provider-retry-oversized"
	oversized := &waE2E.Message{VideoMessage: &waE2E.VideoMessage{
		MediaKey: mediaKey, FileLength: proto.Uint64((1 << 20) + 1),
		Mimetype: proto.String("video/mp4"),
	}}
	if err := bridge.rememberMediaRetry(
		t.Context(), "session-retry-digest", oversizedID, oversized, types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.NewJID("5511999999999", types.DefaultUserServer),
				Sender:   types.NewJID("5511999999999", types.DefaultUserServer),
				IsFromMe: false,
			},
			ID: oversizedID,
		},
	); err != nil {
		t.Fatalf("remember oversized descriptor: %v", err)
	}
	expected, err = persistence.GetMediaRetryState(t.Context(), "session-retry-digest", oversizedID)
	if err != nil {
		t.Fatalf("get oversized retry state: %v", err)
	}
	if _, claimed, claimErr := persistence.CompareAndBeginMediaRetry(t.Context(), expected, time.Now()); claimErr != nil || !claimed {
		t.Fatalf("claim oversized retry state: claimed=%t err=%v", claimed, claimErr)
	}
	oversizedNotification := &waMmsRetry.MediaRetryNotification{
		DirectPath: proto.String("/retry/oversized"),
		Result:     waMmsRetry.MediaRetryNotification_SUCCESS.Enum(),
	}
	oversizedPlain, _ := proto.Marshal(oversizedNotification)
	oversizedCiphertext, _ := gcmutil.Encrypt(key, iv, oversizedPlain, []byte(oversizedID))
	bridge.handle(t.Context(), "session-retry-digest", client, &events.MediaRetry{
		Ciphertext: oversizedCiphertext, IV: iv, Timestamp: time.Now(),
		MessageID: oversizedID, ChatID: types.NewJID("5511999991234", types.DefaultUserServer),
	})
	eventsAfterLimit := pendingEvents(t, persistence)
	limitFailure := mediaRetryPayload(t, eventsAfterLimit, oversizedID)
	if limitFailure["status"] != "FAILED" ||
		limitFailure["error_code"] != "MEDIA_RETRY_DOWNLOAD_FAILED" {
		t.Fatalf("oversized retry was not rejected: %+v", limitFailure)
	}
}

func TestEventBridgeDecryptFailuresExposeOnlyStableCodes(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	client := &fakeEventBridgeClient{secretErr: errors.New("decrypt-key-secret")}
	contact := types.NewJID("5511999991234", types.DefaultUserServer)
	bridge.handle(t.Context(), "session-decrypt-failure", client, &events.Message{
		Info: types.MessageInfo{MessageSource: types.MessageSource{Chat: contact, Sender: contact}, ID: "provider-secret-failure", Timestamp: time.Now()},
		Message: &waE2E.Message{SecretEncryptedMessage: &waE2E.SecretEncryptedMessage{
			TargetMessageKey: &waCommon.MessageKey{ID: proto.String("provider-secret-target")},
			EncPayload:       []byte("encrypted-payload-secret"),
		}},
	})
	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventGatewayAlert)
	if payload["code"] != "SECRET_MESSAGE_DECRYPT_FAILED" {
		t.Fatalf("unexpected decrypt failure payload: %+v", payload)
	}
	assertPayloadsDoNotContain(t, pending, "decrypt-key-secret", "encrypted-payload-secret")
}

func TestEventBridgeDropsCataloguedExcludedEventFamiliesBeforeLedger(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	excluded := []any{
		&events.GroupInfo{},
		&events.JoinedGroup{},
		&events.NewsletterJoin{},
		&events.NewsletterLiveUpdate{},
		&events.CallOffer{},
		&events.CallTerminate{},
		&events.FBMessage{},
		&events.NotifyAccountReachoutTimelock{},
	}
	for _, event := range excluded {
		bridge.handle(t.Context(), "session-excluded-events", nil, event)
	}
	metrics, err := persistence.Metrics(t.Context())
	if err != nil || metrics.PendingEvents != 0 {
		t.Fatalf("excluded upstream event reached ledger: metrics=%+v err=%v", metrics, err)
	}
	if bridge.RejectedScopeCount() != uint64(len(excluded)) {
		t.Fatalf("unexpected rejected event count: got=%d want=%d", bridge.RejectedScopeCount(), len(excluded))
	}
}

func pendingEvents(t *testing.T, persistence *store.Memory) []domain.PendingEvent {
	t.Helper()
	pending, err := persistence.NextEvents(t.Context(), 1000, time.Now().Add(time.Second))
	if err != nil {
		t.Fatalf("load pending events: %v", err)
	}
	for _, item := range pending {
		assertEventPayloadAllowlist(t, item.Event)
	}
	return pending
}

func assertEventPayloadAllowlist(t *testing.T, event domain.Event) {
	t.Helper()
	allowedByType := map[domain.EventType][]string{
		domain.EventMessageReceived: {
			"provider_message_id", "provider_type", "family", "from", "source_identity", "kind", "text", "caption",
			"occurred_at", "reply_to", "spool_id",
			"media_size_bytes", "media_sha256", "mime_type", "filename", "media_error_code", "location",
			"contacts", "poll", "interactive", "rich_card", "link_preview", "ptt", "gif", "animated", "duration_seconds",
			"content_present", "variants", "direction", "history", "media_state", "ephemeral",
		},
		domain.EventMessageStatusChanged: {"provider_message_id", "status", "error_code"},
		domain.EventMessageActionReceived: {
			"action", "provider_message_id", "target_message_id", "from", "source_identity", "kind",
			"provider_type", "family", "text", "caption", "emoji", "option_names",
			"option_hashes", "content_present", "variants", "history",
		},
		domain.EventSessionStatusChanged: {"status", "reason_code", "retry_after_seconds"},
		domain.EventPairingUpdated:       {"event", "code", "expires_at", "error_code", "passkey_request"},
		domain.EventMediaReady:           {"provider_message_id", "spool_id", "size_bytes", "sha256", "mime_type", "filename"},
		domain.EventChatPresenceChanged:  {"from", "presence", "media", "ttl_seconds"},
		domain.EventPresenceChanged:      {"from", "available", "last_seen", "ttl_seconds"},
		domain.EventContactProfileChanged: {
			"user", "display_name", "business_name", "picture_id", "about",
			"source", "push_name", "address_book_name", "address_book_full_name", "address_book_first_name",
			"verified_name", "cleared_fields", "source_identity", "from_full_sync",
		},
		domain.EventIdentityChanged:  {"user", "change"},
		domain.EventPrivacyChanged:   {"settings"},
		domain.EventBlocklistChanged: {"action", "users"},
		domain.EventChatStateChanged: {"to", "action", "value", "target_message_id", "label_id"},
		domain.EventHistorySynced: {
			"batch_id", "cursor", "complete", "messages", "sync_type", "chunk_order", "progress",
			"message_count", "rejected_count", "truncated",
		},
		domain.EventSyncStatusChanged: {"component", "status", "error_code"},
		domain.EventMediaRetryUpdated: {
			"provider_message_id", "status", "spool_id", "error_code", "generation", "attempt",
			"size_bytes", "sha256", "mime_type", "filename",
		},
		domain.EventGatewayAlert: {"code", "severity", "retryable", "retry_after_seconds"},
	}
	allowedList, known := allowedByType[event.Type]
	if !known {
		t.Fatalf("event type %s has no payload allowlist", event.Type)
	}
	allowed := make(map[string]struct{}, len(allowedList))
	for _, key := range allowedList {
		allowed[key] = struct{}{}
	}
	payload := decodeEventPayload(t, event.Payload)
	requiredByType := map[domain.EventType][]string{
		domain.EventMessageReceived:       {"provider_message_id", "from", "kind"},
		domain.EventMessageStatusChanged:  {"provider_message_id", "status"},
		domain.EventMessageActionReceived: {"action", "target_message_id", "from"},
		domain.EventSessionStatusChanged:  {"status"},
		domain.EventPairingUpdated:        {"event"},
		domain.EventMediaReady:            {"provider_message_id", "spool_id", "size_bytes", "sha256", "mime_type"},
		domain.EventChatPresenceChanged:   {"from", "presence", "ttl_seconds"},
		domain.EventPresenceChanged:       {"from", "available", "ttl_seconds"},
		domain.EventContactProfileChanged: {"user"},
		domain.EventIdentityChanged:       {"user", "change"},
		domain.EventPrivacyChanged:        {"settings"},
		domain.EventBlocklistChanged:      {"action", "users"},
		domain.EventChatStateChanged:      {"to", "action"},
		domain.EventHistorySynced:         {"batch_id", "messages", "complete"},
		domain.EventSyncStatusChanged:     {"component", "status"},
		domain.EventMediaRetryUpdated:     {"provider_message_id", "status"},
		domain.EventGatewayAlert:          {"code", "severity", "retryable"},
	}
	for _, required := range requiredByType[event.Type] {
		if _, exists := payload[required]; !exists {
			t.Fatalf("event %s omitted required key %q in %s", event.Type, required, event.Payload)
		}
	}
	for key := range payload {
		if _, ok := allowed[key]; !ok {
			t.Fatalf("event %s emitted non-allowlisted key %q in %s", event.Type, key, event.Payload)
		}
	}
}

func payloadForType(t *testing.T, pending []domain.PendingEvent, eventType domain.EventType) map[string]any {
	t.Helper()
	for _, item := range pending {
		if item.Event.Type == eventType {
			return decodeEventPayload(t, item.Event.Payload)
		}
	}
	t.Fatalf("event type %s not found", eventType)
	return nil
}

func mediaRetryPayload(
	t *testing.T,
	pending []domain.PendingEvent,
	providerMessageID string,
) map[string]any {
	t.Helper()
	for _, item := range pending {
		if item.Event.Type != domain.EventMediaRetryUpdated {
			continue
		}
		payload := decodeEventPayload(t, item.Event.Payload)
		if payload["provider_message_id"] == providerMessageID {
			return payload
		}
	}
	t.Fatalf("media retry event for %s not found", providerMessageID)
	return nil
}

func payloadForAction(t *testing.T, pending []domain.PendingEvent, action string) map[string]any {
	t.Helper()
	for _, item := range pending {
		if item.Event.Type != domain.EventMessageActionReceived {
			continue
		}
		payload := decodeEventPayload(t, item.Event.Payload)
		if payload["action"] == action {
			return payload
		}
	}
	t.Fatalf("message action %s not found", action)
	return nil
}

func chatStateActions(t *testing.T, pending []domain.PendingEvent) map[string]bool {
	t.Helper()
	actions := make(map[string]bool)
	for _, item := range pending {
		if item.Event.Type != domain.EventChatStateChanged {
			continue
		}
		payload := decodeEventPayload(t, item.Event.Payload)
		action, _ := payload["action"].(string)
		actions[action] = true
	}
	return actions
}

func profilePayloadContains(t *testing.T, pending []domain.PendingEvent, key, expected string) bool {
	t.Helper()
	for _, item := range pending {
		if item.Event.Type != domain.EventContactProfileChanged {
			continue
		}
		payload := decodeEventPayload(t, item.Event.Payload)
		if payload[key] == expected {
			return true
		}
	}
	return false
}

func contactProfilePayloads(t *testing.T, pending []domain.PendingEvent) []map[string]any {
	t.Helper()
	profiles := make([]map[string]any, 0)
	for _, item := range pending {
		if item.Event.Type == domain.EventContactProfileChanged {
			profiles = append(profiles, decodeEventPayload(t, item.Event.Payload))
		}
	}
	return profiles
}

func containsString(value any, expected string) bool {
	items, ok := value.([]any)
	if !ok {
		return false
	}
	for _, item := range items {
		if item == expected {
			return true
		}
	}
	return false
}

func historyMessageByID(t *testing.T, messages []any, providerMessageID string) map[string]any {
	t.Helper()
	for _, item := range messages {
		message := item.(map[string]any)
		if message["provider_message_id"] == providerMessageID {
			return message
		}
	}
	t.Fatalf("history message %s not found", providerMessageID)
	return nil
}

func decodeEventPayload(t *testing.T, payload json.RawMessage) map[string]any {
	t.Helper()
	var decoded map[string]any
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatalf("decode event payload: %v", err)
	}
	return decoded
}

func assertPayloadsDoNotContain(t *testing.T, pending []domain.PendingEvent, forbidden ...string) {
	t.Helper()
	for _, item := range pending {
		serialized := string(item.Event.Payload)
		for _, value := range forbidden {
			if strings.Contains(serialized, value) {
				t.Fatalf("event %s leaked forbidden value %q: %s", item.Event.Type, value, serialized)
			}
		}
	}
}

func assertNoSensitivePayloadKeys(t *testing.T, payload json.RawMessage) {
	t.Helper()
	var decoded any
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatalf("decode payload for sensitive key audit: %v", err)
	}
	forbidden := map[string]struct{}{
		"raw": {}, "node": {}, "token": {}, "qr": {}, "media_key": {}, "direct_path": {},
		"ciphertext": {}, "iv": {}, "secret": {}, "dhash": {}, "prev_dhash": {},
	}
	var inspect func(any)
	inspect = func(value any) {
		switch typed := value.(type) {
		case map[string]any:
			for key, nested := range typed {
				if _, blocked := forbidden[strings.ToLower(key)]; blocked {
					t.Fatalf("sensitive payload key %q found in %s", key, payload)
				}
				inspect(nested)
			}
		case []any:
			for _, nested := range typed {
				inspect(nested)
			}
		}
	}
	inspect(decoded)
}
