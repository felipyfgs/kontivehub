package command

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type fakeStickerMaterializationTransport struct {
	fakeTransport
	result  protocol.StickerMaterializationResult
	err     error
	calls   int
	payload domain.StickerMaterializationPayload
}

func (f *fakeStickerMaterializationTransport) MaterializeSticker(
	_ context.Context,
	_ string,
	payload domain.StickerMaterializationPayload,
) (protocol.StickerMaterializationResult, error) {
	f.calls++
	f.payload = payload
	if f.err != nil {
		return protocol.StickerMaterializationResult{}, f.err
	}
	return f.result, nil
}

func TestWorkerEmitsReadyStickerMaterializationEventIdempotently(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeStickerMaterializationTransport{
		result: protocol.StickerMaterializationResult{
			SpoolID: "sticker-materialized-ready01", SizeBytes: 512,
			SHA256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
			MIMEType: "image/webp",
		},
	}
	manager := session.NewManager(persistence, transport, "replica-sticker-ready", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-sticker-ready")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	seedOwnedSession(t, persistence, manager, "session-sticker-ready")

	payload, _ := json.Marshal(domain.StickerMaterializationPayload{
		ObservationID: "observe-ready-0001",
		ExpectedSHA256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-sticker-ready1",
		SessionID: "session-sticker-ready", Type: domain.CommandMaterializeSticker,
		Payload: payload, Digest: "sticker-ready-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept materialize command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process materialize: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("repeat materialize tick: %v", err)
	}
	if transport.calls != 1 {
		t.Fatalf("materialize command was not idempotent: calls=%d", transport.calls)
	}

	event := findPendingEvent(t, persistence, domain.EventStickerMaterialized)
	var body map[string]any
	if err := json.Unmarshal(event.Payload, &body); err != nil {
		t.Fatalf("decode materialization event: %v", err)
	}
	if body["status"] != "READY" || body["observation_id"] != "observe-ready-0001" ||
		body["spool_id"] != "sticker-materialized-ready01" ||
		body["sha256"] != "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" ||
		body["mime_type"] != "image/webp" || body["size_bytes"] != float64(512) {
		t.Fatalf("unexpected ready materialization event: %+v", body)
	}
	encoded, _ := json.Marshal(body)
	for _, secret := range []string{"media_key", "direct_path", "/private"} {
		if strings.Contains(string(encoded), secret) {
			t.Fatalf("materialization event leaked transport secret %q", secret)
		}
	}
}

func TestWorkerRetriesThenEmitsTerminalStickerMaterializationFailure(t *testing.T) {
	t.Parallel()

	t.Run("retryable failure schedules retry", func(t *testing.T) {
		t.Parallel()
		persistence := store.NewMemory()
		transport := &fakeStickerMaterializationTransport{
			err: &protocol.StickerMaterializationError{Reason: "DOWNLOAD_FAILED", Retryable: true},
		}
		manager := session.NewManager(persistence, transport, "replica-sticker-retry", 10, time.Minute, time.Second)
		worker := New(persistence, manager, nil, transport, "replica-sticker-retry")
		now := time.Now().UTC()
		current := now
		worker.now = func() time.Time { return current }
		seedOwnedSession(t, persistence, manager, "session-sticker-retry")
		acceptStickerMaterializeCommand(t, persistence, "command-sticker-retry1", "session-sticker-retry", now)

		if err := worker.ProcessOnce(t.Context()); err != nil {
			t.Fatalf("process retryable failure: %v", err)
		}
		if transport.calls != 1 {
			t.Fatalf("unexpected calls: %d", transport.calls)
		}
		if events := mustPendingEvents(t, persistence); len(events) != 0 {
			t.Fatalf("retryable failure emitted terminal event early: %+v", events)
		}
		current = now.Add(2 * time.Second)
		if err := worker.ProcessOnce(t.Context()); err != nil {
			t.Fatalf("process scheduled retry: %v", err)
		}
		if transport.calls != 2 {
			t.Fatalf("retryable command was not retried: calls=%d", transport.calls)
		}
	})

	t.Run("terminal failure emits failed event", func(t *testing.T) {
		t.Parallel()
		persistence := store.NewMemory()
		transport := &fakeStickerMaterializationTransport{
			err: &protocol.StickerMaterializationError{Reason: "MEDIA_EXPIRED", Retryable: false},
		}
		manager := session.NewManager(persistence, transport, "replica-sticker-fail", 10, time.Minute, time.Second)
		worker := New(persistence, manager, nil, transport, "replica-sticker-fail")
		now := time.Now().UTC()
		worker.now = func() time.Time { return now }
		seedOwnedSession(t, persistence, manager, "session-sticker-fail")
		acceptStickerMaterializeCommand(t, persistence, "command-sticker-fail01", "session-sticker-fail", now)

		if err := worker.ProcessOnce(t.Context()); err != nil {
			t.Fatalf("process terminal failure: %v", err)
		}
		if err := worker.ProcessOnce(t.Context()); err != nil {
			t.Fatalf("repeat terminal failure tick: %v", err)
		}
		if transport.calls != 1 {
			t.Fatalf("terminal failure was retried: calls=%d", transport.calls)
		}
		event := findPendingEvent(t, persistence, domain.EventStickerMaterialized)
		var body map[string]any
		_ = json.Unmarshal(event.Payload, &body)
		if body["status"] != "FAILED" || body["error_code"] != "MEDIA_EXPIRED" {
			t.Fatalf("unexpected terminal materialization event: %+v", body)
		}
	})

	t.Run("retry budget exhaustion emits failed event", func(t *testing.T) {
		t.Parallel()
		persistence := store.NewMemory()
		transport := &fakeStickerMaterializationTransport{
			err: &protocol.StickerMaterializationError{Reason: "DOWNLOAD_FAILED", Retryable: true},
		}
		manager := session.NewManager(persistence, transport, "replica-sticker-budget", 10, time.Minute, time.Second)
		worker := New(persistence, manager, nil, transport, "replica-sticker-budget")
		worker.maxAttempts = 1
		now := time.Now().UTC()
		worker.now = func() time.Time { return now }
		seedOwnedSession(t, persistence, manager, "session-sticker-budget")
		acceptStickerMaterializeCommand(t, persistence, "command-sticker-budget1", "session-sticker-budget", now)

		if err := worker.ProcessOnce(t.Context()); err != nil {
			t.Fatalf("process exhausted retry: %v", err)
		}
		event := findPendingEvent(t, persistence, domain.EventStickerMaterialized)
		var body map[string]any
		_ = json.Unmarshal(event.Payload, &body)
		if body["status"] != "FAILED" || body["error_code"] != "DOWNLOAD_FAILED" {
			t.Fatalf("unexpected exhausted materialization event: %+v", body)
		}
	})
}

func TestWorkerRejectsDuplicateStickerMaterializeWithoutSecondDownload(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeStickerMaterializationTransport{
		result: protocol.StickerMaterializationResult{
			SpoolID: "sticker-materialized-dup0001", SizeBytes: 100,
			SHA256: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
			MIMEType: "image/webp",
		},
	}
	manager := session.NewManager(persistence, transport, "replica-sticker-dup", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-sticker-dup")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	seedOwnedSession(t, persistence, manager, "session-sticker-dup")
	acceptStickerMaterializeCommand(t, persistence, "command-sticker-dup0001", "session-sticker-dup", now)

	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("first process: %v", err)
	}
	duplicate, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-sticker-dup0001",
		SessionID: "session-sticker-dup", Type: domain.CommandMaterializeSticker,
		Payload: mustStickerMaterializePayload(), Digest: "sticker-materialize-command-sticker-dup0001", AcceptedAt: now,
	})
	if err != nil || !duplicate {
		t.Fatalf("duplicate command id was not acknowledged: duplicate=%v err=%v", duplicate, err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("second process: %v", err)
	}
	if transport.calls != 1 {
		t.Fatalf("unexpected materialize calls: %d", transport.calls)
	}
}

func seedOwnedSession(
	t *testing.T,
	persistence *store.Memory,
	manager *session.Manager,
	sessionID string,
) {
	t.Helper()
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: sessionID, Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("upsert session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}
}

func acceptStickerMaterializeCommand(
	t *testing.T,
	persistence *store.Memory,
	commandID string,
	sessionID string,
	now time.Time,
) {
	t.Helper()
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: commandID, SessionID: sessionID,
		Type: domain.CommandMaterializeSticker, Payload: mustStickerMaterializePayload(),
		Digest: "sticker-materialize-" + commandID, AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept command: %v", err)
	}
}

func mustStickerMaterializePayload() json.RawMessage {
	payload, _ := json.Marshal(domain.StickerMaterializationPayload{
		ObservationID: "observe-ready-0001",
		ExpectedSHA256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	return payload
}

func findPendingEvent(t *testing.T, persistence *store.Memory, eventType domain.EventType) domain.Event {
	t.Helper()
	for _, pending := range mustPendingEvents(t, persistence) {
		if pending.Event.Type == eventType {
			return pending.Event
		}
	}
	t.Fatalf("event %s not found", eventType)
	return domain.Event{}
}
