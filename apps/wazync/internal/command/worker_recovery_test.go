package command

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type fakeRecoveryTransport struct {
	fakeTransport
	historyCalls int
	retryCalls   int
	history      domain.HistorySyncPayload
	retry        domain.MediaRetryPayload
}

func (f *fakeRecoveryTransport) RequestHistorySync(
	_ context.Context,
	_ string,
	payload domain.HistorySyncPayload,
) error {
	f.historyCalls++
	f.history = payload
	return nil
}

func (f *fakeRecoveryTransport) RetryMedia(
	_ context.Context,
	_ string,
	payload domain.MediaRetryPayload,
) error {
	f.retryCalls++
	f.retry = payload
	return nil
}

func TestWorkerRoutesHistoryAndMediaRecoveryOnlyUnderOwnedLease(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeRecoveryTransport{}
	manager := session.NewManager(persistence, transport, "replica-recovery", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-recovery")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-recovery-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("upsert session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}

	historyPayload, _ := json.Marshal(domain.HistorySyncPayload{
		To: "+5511999991234", LastMessageID: "provider-cursor-0001",
		LastMessageFrom: "+5511999991234", LastMessageTimestamp: 1_700_000_000,
		Count: 50,
	})
	retryPayload, _ := json.Marshal(domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-media-0001",
		Sender: "+5511999991234", FromMe: false,
	})
	commands := []domain.Command{
		{
			ContractVersion: "v1", CommandID: "command-history-0001", SessionID: "session-recovery-0001",
			Type: domain.CommandRequestHistorySync, Payload: historyPayload, Digest: "history-digest", AcceptedAt: now,
		},
		{
			ContractVersion: "v1", CommandID: "command-retry-000001", SessionID: "session-recovery-0001",
			Type: domain.CommandRetryMedia, Payload: retryPayload, Digest: "retry-digest", AcceptedAt: now,
		},
	}
	for _, command := range commands {
		if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
			t.Fatalf("accept %s: %v", command.Type, err)
		}
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process recovery commands: %v", err)
	}
	if transport.historyCalls != 1 || transport.history.Count != 50 ||
		transport.retryCalls != 1 || transport.retry.TargetMessageID != "provider-media-0001" {
		t.Fatalf("unexpected recovery routing: %+v", transport)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("repeat worker tick: %v", err)
	}
	if transport.historyCalls != 1 || transport.retryCalls != 1 {
		t.Fatalf("processed recovery commands were not idempotent: %+v", transport)
	}
}
