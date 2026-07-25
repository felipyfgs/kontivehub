package session

import (
	"context"
	"encoding/json"
	"errors"
	"sync/atomic"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type fakePairer struct{}

func (fakePairer) StartPairing(context.Context, string) (<-chan domain.PairingUpdate, error) {
	updates := make(chan domain.PairingUpdate, 2)
	updates <- domain.PairingUpdate{Event: "code", Code: "private-qr-code", ExpiresAt: time.Now().Add(time.Minute)}
	updates <- domain.PairingUpdate{Event: "success"}
	close(updates)
	return updates, nil
}

type failingPairer struct{ err error }

func (p failingPairer) StartPairing(context.Context, string) (<-chan domain.PairingUpdate, error) {
	return nil, p.err
}

type fakeRecorder struct {
	calls       atomic.Int32
	forgetCalls atomic.Int32
	err         error
}

func (r *fakeRecorder) RecordDevice(context.Context, string) error {
	r.calls.Add(1)
	return r.err
}

func (r *fakeRecorder) ForgetSession(context.Context, string) error {
	r.forgetCalls.Add(1)
	return nil
}

func TestPairingPersistsSynchronousAlreadyPairedFailure(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-already-paired-0001")
	coordinator := NewPairingCoordinator(
		persistence, failingPairer{err: domain.ErrSessionAlreadyPaired}, nil,
	)

	err := coordinator.Start(t.Context(), "session-already-paired-0001")
	if !errors.Is(err, ErrPairingTerminal) || !errors.Is(err, domain.ErrSessionAlreadyPaired) {
		t.Fatalf("expected joined terminal pairing error, got %v", err)
	}
	pending, getErr := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
	if getErr != nil || len(pending) != 2 {
		t.Fatalf("terminal pairing event missing: count=%d err=%v", len(pending), getErr)
	}
	var payload map[string]any
	var pairingEvent domain.Event
	for _, candidate := range pending {
		if candidate.Event.Type == domain.EventPairingUpdated {
			pairingEvent = candidate.Event
		}
	}
	if err := json.Unmarshal(pairingEvent.Payload, &payload); err != nil {
		t.Fatalf("decode pairing payload: %v", err)
	}
	if payload["event"] != "error" || payload["error_code"] != "SESSION_ALREADY_PAIRED" {
		t.Fatalf("unexpected terminal payload: %+v", payload)
	}
	if _, leaksExpiry := payload["expires_at"]; leaksExpiry {
		t.Fatalf("zero expiry must not be serialized: %+v", payload)
	}
	session, _ := persistence.GetSession(t.Context(), "session-already-paired-0001")
	if session.Status != domain.SessionDisconnected || session.DesiredConnected {
		t.Fatalf("already-paired session did not finish disconnected: %+v", session)
	}
}

func TestPairingPersistsUpdatesBeforeDeliveryAndRecordsSuccessfulDevice(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-pairing-0001")
	recorder := &fakeRecorder{}
	coordinator := NewPairingCoordinator(persistence, fakePairer{}, recorder)
	if err := coordinator.Start(t.Context(), "session-pairing-0001"); err != nil {
		t.Fatalf("start pairing: %v", err)
	}

	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		session, _ := persistence.GetSession(t.Context(), "session-pairing-0001")
		metrics, _ := persistence.Metrics(t.Context())
		if session.Status == domain.SessionConnected && metrics.PendingEvents == 3 && recorder.calls.Load() == 1 {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("pairing updates were not durably materialized")
}

func TestPairingDoesNotPublishConnectedWhenDeviceMappingFails(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-mapping-failure-0001")
	recorder := &fakeRecorder{err: errors.New("database detail must stay internal")}
	disconnector := &recordingDisconnector{}
	coordinator := NewPairingCoordinator(persistence, multiPairer{
		Pairer:       fakePairer{},
		disconnector: disconnector,
	}, recorder)
	if err := coordinator.Start(t.Context(), "session-mapping-failure-0001"); err != nil {
		t.Fatalf("start pairing: %v", err)
	}

	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		state, _ := persistence.GetSession(t.Context(), "session-mapping-failure-0001")
		if state.Status == domain.SessionDisconnected && !state.DesiredConnected &&
			recorder.calls.Load() == 1 && recorder.forgetCalls.Load() == 1 {
			pending, _ := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
			for _, item := range pending {
				if item.Event.Type != domain.EventPairingUpdated {
					continue
				}
				var payload map[string]any
				if err := json.Unmarshal(item.Event.Payload, &payload); err != nil {
					t.Fatalf("decode pairing event: %v", err)
				}
				if payload["event"] == "success" {
					t.Fatal("success was published before the device mapping")
				}
			}
			if disconnector.calls.Load() != 1 {
				t.Fatal("failed pairing did not disconnect the provisional client")
			}
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("mapping failure did not terminate the pairing")
}

type recordingDisconnector struct{ calls atomic.Int32 }

func (d *recordingDisconnector) Disconnect(string) { d.calls.Add(1) }

type multiPairer struct {
	Pairer
	disconnector *recordingDisconnector
}

func (p multiPairer) Disconnect(sessionID string) {
	p.disconnector.Disconnect(sessionID)
}
