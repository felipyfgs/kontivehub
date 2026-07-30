package command

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type failingMediaRetryTransport struct {
	fakeRecoveryTransport
	err error
}

func (f *failingMediaRetryTransport) RetryMedia(context.Context, string, domain.MediaRetryPayload) error {
	f.retryCalls++
	if f.err != nil {
		return f.err
	}
	return protocol.ErrMediaRetryStateMissing
}

type claimLostMediaRetryTransport struct{ fakeRecoveryTransport }

func (f *claimLostMediaRetryTransport) RetryMedia(context.Context, string, domain.MediaRetryPayload) error {
	f.retryCalls++
	return protocol.ErrMediaRetryClaimLost
}

type failingTerminalEventStore struct {
	*store.Memory
	finalizeCalls int
}

func (s *failingTerminalEventStore) FinalizeCommandFailureWithEvent(
	context.Context, string, int, time.Time, string, domain.Event,
) error {
	s.finalizeCalls++
	return errors.New("terminal projection persistence failed")
}

func TestWorkerEmitsAllowlistedTerminalMediaRetryFailure(t *testing.T) {
	t.Parallel()

	for _, test := range []struct {
		name        string
		err         error
		code        string
		maxAttempts int
	}{
		{name: "missing state", err: protocol.ErrMediaRetryStateMissing, code: "MEDIA_RETRY_STATE_MISSING", maxAttempts: 10},
		{name: "invalid request", err: protocol.ErrHistoryRecoveryInvalid, code: "MEDIA_RETRY_INVALID_REQUEST", maxAttempts: 10},
		{name: "provider exhausted", err: errors.New("provider unavailable"), code: "MEDIA_RETRY_REQUEST_FAILED", maxAttempts: 1},
	} {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			persistence := store.NewMemory()
			transport := &failingMediaRetryTransport{err: test.err}
			manager := session.NewManager(persistence, transport, "replica-terminal-retry", 10, time.Minute, time.Second)
			worker := New(persistence, manager, nil, transport, "replica-terminal-retry")
			worker.maxAttempts = test.maxAttempts
			now := time.Now().UTC()
			worker.now = func() time.Time { return now }
			if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: "session-terminal-retry", Status: domain.SessionConnecting, DesiredConnected: true}); err != nil {
				t.Fatalf("seed session: %v", err)
			}
			if err := manager.Reconcile(t.Context()); err != nil {
				t.Fatalf("claim session: %v", err)
			}
			payload := json.RawMessage(`{"to":"+5511999991234","target_message_id":"provider-terminal-retry","expected_direction":"OUTBOUND"}`)
			if _, err := persistence.AcceptCommand(t.Context(), domain.Command{ContractVersion: "v1", CommandID: "command-terminal-retry", SessionID: "session-terminal-retry", Type: domain.CommandRetryMedia, Payload: payload, Digest: "retry-terminal-digest", AcceptedAt: now}); err != nil {
				t.Fatalf("accept retry: %v", err)
			}
			if err := worker.ProcessOnce(t.Context()); err != nil {
				t.Fatalf("process terminal retry: %v", err)
			}
			if err := worker.ProcessOnce(t.Context()); err != nil {
				t.Fatalf("repeat terminal retry tick: %v", err)
			}
			if transport.retryCalls != 1 {
				t.Fatalf("deterministic failure retried: calls=%d", transport.retryCalls)
			}
			for _, pending := range mustPendingEvents(t, persistence) {
				if pending.Event.Type != domain.EventMediaRetryUpdated {
					continue
				}
				var event map[string]any
				_ = json.Unmarshal(pending.Event.Payload, &event)
				if event["status"] != "FAILED" || event["error_code"] != test.code || event["provider_message_id"] != "provider-terminal-retry" || event["generation"] != float64(0) || event["attempt"] != float64(0) {
					t.Fatalf("unexpected terminal retry event: %+v", event)
				}
				return
			}
			t.Fatal("terminal retry failure event not emitted")
		})
	}
}

func TestWorkerAcknowledgesLostMediaRetryClaimWithoutRecyclingCommand(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &claimLostMediaRetryTransport{}
	manager := session.NewManager(persistence, transport, "replica-claim-lost", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-claim-lost")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: "session-claim-lost", Status: domain.SessionConnecting, DesiredConnected: true}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}
	payload := json.RawMessage(`{"to":"+5511999991234","target_message_id":"provider-claim-lost","expected_direction":"OUTBOUND"}`)
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{ContractVersion: "v1", CommandID: "command-claim-lost", SessionID: "session-claim-lost", Type: domain.CommandRetryMedia, Payload: payload, Digest: "claim-lost-digest", AcceptedAt: now}); err != nil {
		t.Fatalf("accept retry: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process lost claim: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("repeat worker tick: %v", err)
	}
	if transport.retryCalls != 1 {
		t.Fatalf("lost claim command was recycled: retry calls=%d", transport.retryCalls)
	}
}

func TestWorkerDoesNotTerminallyAcknowledgeRetryBeforeFailedEventPersists(t *testing.T) {
	t.Parallel()
	persistence := &failingTerminalEventStore{Memory: store.NewMemory()}
	transport := &failingMediaRetryTransport{}
	manager := session.NewManager(persistence, transport, "replica-terminal-store", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-terminal-store")
	worker.maxAttempts = 1
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: "session-terminal-store", Status: domain.SessionConnecting, DesiredConnected: true}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}
	payload := json.RawMessage(`{"to":"+5511999991234","target_message_id":"provider-terminal-store","expected_direction":"OUTBOUND"}`)
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{ContractVersion: "v1", CommandID: "command-terminal-store", SessionID: "session-terminal-store", Type: domain.CommandRetryMedia, Payload: payload, Digest: "retry-terminal-store", AcceptedAt: now}); err != nil {
		t.Fatalf("accept retry: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err == nil {
		t.Fatal("terminal retry succeeded despite failed FAILED projection")
	}
	if persistence.finalizeCalls != 1 {
		t.Fatalf("terminal command finalization calls: %d", persistence.finalizeCalls)
	}
	if events := mustPendingEvents(t, persistence.Memory); len(events) != 0 {
		t.Fatalf("FAILED projection was partially persisted: %+v", events)
	}
}

func TestTerminalMediaRetryFailureEventIsDeterministic(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &failingMediaRetryTransport{}
	manager := session.NewManager(persistence, transport, "replica-terminal-event", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-terminal-event")
	command := domain.Command{CommandID: "command-terminal-event", SessionID: "session-terminal-event", Payload: json.RawMessage(`{"to":"+5511999991234","target_message_id":"provider-terminal-event","expected_direction":"OUTBOUND"}`)}
	if err := worker.appendMediaRetryFailure(t.Context(), command, "MEDIA_RETRY_STATE_MISSING", time.Now()); err != nil {
		t.Fatalf("append failed event: %v", err)
	}
	if err := worker.appendMediaRetryFailure(t.Context(), command, "MEDIA_RETRY_STATE_MISSING", time.Now().Add(time.Second)); err != nil {
		t.Fatalf("duplicate failed event was not idempotent: %v", err)
	}
	events := mustPendingEvents(t, persistence)
	if len(events) != 1 || !strings.HasPrefix(events[0].Event.EventID, "media-retry-terminal-") {
		t.Fatalf("terminal failure event was not deterministic: %+v", events)
	}
}

func TestTerminalMediaRetryFailureEventUsesAuthoritativeGenerationAndAttempt(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &failingMediaRetryTransport{}
	manager := session.NewManager(persistence, transport, "replica-terminal-generation", 10, time.Minute, time.Second)
	worker := New(persistence, manager, nil, transport, "replica-terminal-generation")
	command := domain.Command{CommandID: "command-terminal-generation", SessionID: "session-terminal-generation", Payload: json.RawMessage(`{"to":"+5511999991234","target_message_id":"provider-terminal-generation","expected_direction":"OUTBOUND"}`)}
	initial := domain.MediaRetryState{
		SessionID: command.SessionID, MessageID: "provider-terminal-generation", Descriptor: []byte("opaque-descriptor"),
	}
	if err := persistence.PutMediaRetryState(t.Context(), initial); err != nil {
		t.Fatalf("put retry state: %v", err)
	}
	claimedState, claimed, err := persistence.CompareAndBeginMediaRetry(t.Context(), initial, time.Now().UTC())
	if err != nil || !claimed {
		t.Fatalf("begin retry state: claimed=%v err=%v", claimed, err)
	}
	if _, claimed, err := persistence.CompareAndBeginMediaRetry(t.Context(), claimedState, time.Now().UTC()); err != nil || !claimed {
		t.Fatalf("advance concurrent retry state: claimed=%v err=%v", claimed, err)
	}
	event, err := worker.mediaRetryFailureEvent(t.Context(), command, "MEDIA_RETRY_REQUEST_FAILED", time.Now().UTC(), &protocol.MediaRetryClaim{
		Generation: claimedState.Generation,
		Attempt:    claimedState.Attempts,
	})
	if err != nil {
		t.Fatalf("build failed event: %v", err)
	}
	var payload map[string]any
	if err := json.Unmarshal(event.Payload, &payload); err != nil {
		t.Fatalf("decode failed event: %v", err)
	}
	if payload["generation"] != float64(1) || payload["attempt"] != float64(1) {
		t.Fatalf("failed event lost authoritative retry state: %+v", payload)
	}
}

func mustPendingEvents(t *testing.T, persistence *store.Memory) []domain.PendingEvent {
	t.Helper()
	events, err := persistence.NextEvents(t.Context(), 20, time.Now().Add(time.Second))
	if err != nil && !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("read pending events: %v", err)
	}
	return events
}
