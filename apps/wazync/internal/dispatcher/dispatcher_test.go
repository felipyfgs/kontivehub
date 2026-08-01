package dispatcher

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type recordingSpoolAck struct{ ids []string }

func (s *recordingSpoolAck) Ack(id string) error {
	s.ids = append(s.ids, id)
	return nil
}

func TestDispatcherRetriesPersistedEventUntilLaravelAcknowledges(t *testing.T) {
	t.Parallel()
	var calls atomic.Int32
	eventIngestServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, request *http.Request) {
		body := make([]byte, request.ContentLength)
		_, _ = request.Body.Read(body)
		if request.Header.Get(security.HeaderSignature) == "" || request.Header.Get(security.HeaderNonce) == "" {
			t.Error("missing HMAC headers")
		}
		if calls.Add(1) == 1 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	defer eventIngestServer.Close()

	persistence := store.NewMemory()
	payload, _ := json.Marshal(map[string]string{"status": "CONNECTED"})
	digest := sha256.Sum256(payload)
	_, err := persistence.AppendEvent(t.Context(), domain.Event{
		ContractVersion: "v1", EventID: "event-retry-0001", SessionID: "session-retry-0001",
		Type: "SESSION_STATUS_CHANGED", OccurredAt: time.Now(), Payload: payload,
		Digest: hex.EncodeToString(digest[:]),
	})
	if err != nil {
		t.Fatalf("append event: %v", err)
	}

	now := time.Now().UTC()
	dispatcher := New(persistence, eventIngestServer.URL+"/api/internal/v1/whatsapp/events", "gateway-v1", "secret", eventIngestServer.Client())
	dispatcher.now = func() time.Time { return now }
	if err := dispatcher.DispatchOnce(t.Context()); err != nil {
		t.Fatalf("first dispatch: %v", err)
	}
	metrics, _ := persistence.Metrics(t.Context())
	if metrics.PendingEvents != 1 {
		t.Fatalf("failed delivery was lost: %+v", metrics)
	}

	now = now.Add(time.Minute)
	if err := dispatcher.DispatchOnce(t.Context()); err != nil {
		t.Fatalf("second dispatch: %v", err)
	}
	metrics, _ = persistence.Metrics(t.Context())
	if metrics.PendingEvents != 0 || calls.Load() != 2 {
		t.Fatalf("event not acknowledged exactly after retry: metrics=%+v calls=%d", metrics, calls.Load())
	}
}

func TestDispatcherRetainsRetryDescriptorUntilLaravelACKThenDeletesIt(t *testing.T) {
	t.Parallel()
	eventIngestServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	defer eventIngestServer.Close()

	persistence := store.NewMemory()
	state := domain.MediaRetryState{
		SessionID: "session-media-ack", MessageID: "provider-media-ack",
		Descriptor: []byte("encrypted-by-durable-store"),
	}
	if err := persistence.PutMediaRetryState(t.Context(), state); err != nil {
		t.Fatalf("put retry state: %v", err)
	}
	payload, _ := json.Marshal(map[string]any{
		"provider_message_id": state.MessageID, "status": "READY", "spool_id": "spool-media-ack",
	})
	digest := sha256.Sum256(payload)
	_, err := persistence.AppendEvent(t.Context(), domain.Event{
		ContractVersion: "v1", EventID: "event-media-ack", SessionID: state.SessionID,
		Type: domain.EventMediaRetryUpdated, OccurredAt: time.Now(), Payload: payload,
		Digest: hex.EncodeToString(digest[:]),
	})
	if err != nil {
		t.Fatalf("append retry event: %v", err)
	}
	if _, err := persistence.GetMediaRetryState(t.Context(), state.SessionID, state.MessageID); err != nil {
		t.Fatalf("retry descriptor disappeared before ACK: %v", err)
	}

	spoolAck := &recordingSpoolAck{}
	d := New(persistence, eventIngestServer.URL, "gateway-v1", "secret", eventIngestServer.Client()).WithSpool(spoolAck)
	if err := d.DispatchOnce(t.Context()); err != nil {
		t.Fatalf("dispatch retry event: %v", err)
	}
	if _, err := persistence.GetMediaRetryState(
		context.Background(), state.SessionID, state.MessageID,
	); err != domain.ErrNotFound {
		t.Fatalf("retry descriptor remained after ACK: %v", err)
	}
	if len(spoolAck.ids) != 1 || spoolAck.ids[0] != "spool-media-ack" {
		t.Fatalf("spool was not ACKed with retry event: %+v", spoolAck.ids)
	}
}
