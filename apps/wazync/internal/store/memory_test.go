package store

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
)

func TestMemoryStorePersistsCommandsAndEventsIdempotently(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	persistence := NewMemory()
	command := domain.Command{CommandID: "command-0001", Digest: "digest-a"}
	if duplicate, err := persistence.AcceptCommand(ctx, command); err != nil || duplicate {
		t.Fatalf("first command must be persisted: duplicate=%v err=%v", duplicate, err)
	}
	if duplicate, err := persistence.AcceptCommand(ctx, command); err != nil || !duplicate {
		t.Fatalf("same command must be duplicate: duplicate=%v err=%v", duplicate, err)
	}
	command.Digest = "digest-b"
	if _, err := persistence.AcceptCommand(ctx, command); !errors.Is(err, domain.ErrDigestConflict) {
		t.Fatalf("expected command digest conflict, got %v", err)
	}

	event := domain.Event{EventID: "event-id-0001", Digest: "event-digest", OccurredAt: time.Now()}
	if duplicate, err := persistence.AppendEvent(ctx, event); err != nil || duplicate {
		t.Fatalf("first event must be persisted: duplicate=%v err=%v", duplicate, err)
	}
	if duplicate, err := persistence.AppendEvent(ctx, event); err != nil || !duplicate {
		t.Fatalf("same event must be duplicate: duplicate=%v err=%v", duplicate, err)
	}
}

func TestLifecycleCommandCanBootstrapLeaseAndThenRoutesToOwner(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	command := domain.Command{
		CommandID: "command-connect-route-0001", SessionID: "session-connect-route-0001",
		Type: domain.CommandConnectSession, Payload: []byte(`{}`), Digest: "route", AcceptedAt: now,
	}
	if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
		t.Fatalf("accept connect: %v", err)
	}
	selected, err := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	if err != nil || len(selected) != 1 {
		t.Fatalf("connect without lease was not bootstrappable: selected=%d err=%v", len(selected), err)
	}
	if err := persistence.MarkCommandFailed(t.Context(), command.CommandID, now, "RETRY", false); err != nil {
		t.Fatalf("retry connect: %v", err)
	}
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: command.SessionID, Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	leases, err := persistence.ClaimSessions(t.Context(), "replica-b", 1, now, time.Minute)
	if err != nil || len(leases) != 1 {
		t.Fatalf("claim session: leases=%d err=%v", len(leases), err)
	}
	foreign, _ := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	owned, _ := persistence.NextCommands(t.Context(), "replica-b", 1, now)
	if len(foreign) != 0 || len(owned) != 1 {
		t.Fatalf("lifecycle command was not routed to lease owner: foreign=%d owned=%d", len(foreign), len(owned))
	}
}

func TestClaimSessionAcquiresOnlyTheRequestedSessionAndRespectsFencing(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	for _, sessionID := range []string{"session-exact-a-0001", "session-exact-b-0001"} {
		if err := persistence.UpsertSession(t.Context(), domain.Session{
			SessionID: sessionID, Status: domain.SessionDisconnected,
		}); err != nil {
			t.Fatalf("seed %s: %v", sessionID, err)
		}
	}
	lease, claimed, err := persistence.ClaimSession(
		t.Context(), "session-exact-b-0001", "replica-exact", 1, now, time.Minute,
	)
	if err != nil || !claimed || lease.SessionID != "session-exact-b-0001" || lease.FencingToken != 1 {
		t.Fatalf("unexpected exact claim: lease=%+v claimed=%v err=%v", lease, claimed, err)
	}
	if _, claimed, err := persistence.ClaimSession(
		t.Context(), "session-exact-a-0001", "replica-exact", 1, now, time.Minute,
	); err != nil || claimed {
		t.Fatalf("capacity should reject second exact claim: claimed=%v err=%v", claimed, err)
	}
	if err := persistence.ReleaseLease(t.Context(), lease); err != nil {
		t.Fatalf("release exact claim: %v", err)
	}
	takeover, claimed, err := persistence.ClaimSession(
		t.Context(), "session-exact-b-0001", "replica-takeover", 1, now.Add(time.Second), time.Minute,
	)
	if err != nil || !claimed || takeover.FencingToken <= lease.FencingToken {
		t.Fatalf("takeover did not advance fencing: old=%+v new=%+v claimed=%v err=%v", lease, takeover, claimed, err)
	}
}
