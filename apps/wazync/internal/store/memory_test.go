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

func TestMediaRetryCommandFinalizationIsFencedByAttempt(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	command := domain.Command{
		CommandID: "command-media-retry-fence-0001", SessionID: "session-media-retry-fence-0001",
		Type: domain.CommandRetryMedia, Digest: "media-retry-fence", AcceptedAt: now,
	}
	if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: command.SessionID, Status: domain.SessionConnecting}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, claimed, err := persistence.ClaimSession(t.Context(), command.SessionID, "replica-a", 1, now, time.Minute); err != nil || !claimed {
		t.Fatalf("claim first session lease: claimed=%v err=%v", claimed, err)
	}
	if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
		t.Fatalf("accept command: %v", err)
	}
	first, err := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	if err != nil || len(first) != 1 || first[0].Attempts != 1 {
		t.Fatalf("first command attempt: %+v err=%v", first, err)
	}
	reclaimedAt := now.Add(3 * time.Minute)
	if _, claimed, err := persistence.ClaimSession(t.Context(), command.SessionID, "replica-b", 1, reclaimedAt, time.Minute); err != nil || !claimed {
		t.Fatalf("claim reclaimed session lease: claimed=%v err=%v", claimed, err)
	}
	second, err := persistence.NextCommands(t.Context(), "replica-b", 1, reclaimedAt)
	if err != nil || len(second) != 1 || second[0].Attempts != 2 {
		t.Fatalf("reclaimed command attempt: %+v err=%v", second, err)
	}
	if err := persistence.FinalizeMediaRetryCommandProcessed(t.Context(), command.CommandID, first[0].Attempts, now); !errors.Is(err, domain.ErrStateConflict) {
		t.Fatalf("stale attempt finalized command: %v", err)
	}
	if err := persistence.FinalizeMediaRetryCommandProcessed(t.Context(), command.CommandID, second[0].Attempts, now); err != nil {
		t.Fatalf("current attempt did not finalize command: %v", err)
	}
	selected, err := persistence.NextCommands(t.Context(), "replica-a", 1, now.Add(6*time.Minute))
	if err != nil || len(selected) != 0 {
		t.Fatalf("finalized command was recycled: selected=%+v err=%v", selected, err)
	}
}

func TestMediaRetryFinalizationRejectsMissingAndWrongCommandType(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	for _, finalize := range []func() error{
		func() error {
			return persistence.FinalizeMediaRetryCommandProcessed(t.Context(), "missing-media-retry-command", 1, now)
		},
		func() error {
			return persistence.FinalizeMediaRetryCommandFailed(t.Context(), "missing-media-retry-command", 1, now, "FAILED")
		},
	} {
		if err := finalize(); !errors.Is(err, domain.ErrStateConflict) {
			t.Fatalf("missing media retry finalization error = %v, want state conflict", err)
		}
	}

	command := domain.Command{
		CommandID: "command-wrong-finalizer-type-0001", SessionID: "session-wrong-finalizer-type-0001",
		Type: domain.CommandProvisionSession, Digest: "wrong-finalizer-type", AcceptedAt: now,
	}
	if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
		t.Fatalf("accept wrong-type command: %v", err)
	}
	pending, err := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	if err != nil || len(pending) != 1 {
		t.Fatalf("claim wrong-type command: pending=%+v err=%v", pending, err)
	}
	for _, finalize := range []func() error{
		func() error {
			return persistence.FinalizeMediaRetryCommandProcessed(t.Context(), command.CommandID, pending[0].Attempts, now)
		},
		func() error {
			return persistence.FinalizeMediaRetryCommandFailed(t.Context(), command.CommandID, pending[0].Attempts, now, "FAILED")
		},
	} {
		if err := finalize(); !errors.Is(err, domain.ErrStateConflict) {
			t.Fatalf("wrong-type media retry finalization error = %v, want state conflict", err)
		}
	}
}

func TestMediaRetryFailureRespectsRetryAvailability(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	command := domain.Command{
		CommandID: "command-media-retry-availability-0001", SessionID: "session-media-retry-availability-0001",
		Type: domain.CommandRetryMedia, Digest: "media-retry-availability", AcceptedAt: now,
	}
	if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: command.SessionID, Status: domain.SessionConnecting}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, claimed, err := persistence.ClaimSession(t.Context(), command.SessionID, "replica-a", 1, now, 2*time.Minute); err != nil || !claimed {
		t.Fatalf("claim session lease: claimed=%v err=%v", claimed, err)
	}
	if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
		t.Fatalf("accept command: %v", err)
	}
	pending, err := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	if err != nil || len(pending) != 1 || pending[0].Attempts != 1 {
		t.Fatalf("claim first attempt: pending=%+v err=%v", pending, err)
	}

	retryAt := now.Add(time.Minute)
	if err := persistence.FinalizeMediaRetryCommandFailed(
		t.Context(), command.CommandID, pending[0].Attempts, retryAt, "MEDIA_RETRY_REQUEST_FAILED",
	); err != nil {
		t.Fatalf("finalize retry: %v", err)
	}
	beforeRetry, err := persistence.NextCommands(t.Context(), "replica-a", 1, retryAt.Add(-time.Nanosecond))
	if err != nil || len(beforeRetry) != 0 {
		t.Fatalf("retry was selected before availability: pending=%+v err=%v", beforeRetry, err)
	}
	atRetry, err := persistence.NextCommands(t.Context(), "replica-a", 1, retryAt)
	if err != nil || len(atRetry) != 1 || atRetry[0].Attempts != 2 {
		t.Fatalf("retry was not selected at availability: pending=%+v err=%v", atRetry, err)
	}
}

func TestTerminalCommandFailureRejectsConflictingEventDigestOnReplay(t *testing.T) {
	t.Parallel()
	persistence := NewMemory()
	now := time.Now().UTC()
	command := domain.Command{
		CommandID: "command-terminal-digest-0001", SessionID: "session-terminal-digest-0001",
		Type: domain.CommandRetryMedia, Digest: "command-terminal-digest", AcceptedAt: now,
	}
	if err := persistence.UpsertSession(t.Context(), domain.Session{SessionID: command.SessionID, Status: domain.SessionConnecting}); err != nil {
		t.Fatalf("seed session: %v", err)
	}
	if _, claimed, err := persistence.ClaimSession(t.Context(), command.SessionID, "replica-a", 1, now, time.Minute); err != nil || !claimed {
		t.Fatalf("claim session lease: claimed=%v err=%v", claimed, err)
	}
	if _, err := persistence.AcceptCommand(t.Context(), command); err != nil {
		t.Fatalf("accept command: %v", err)
	}
	selected, err := persistence.NextCommands(t.Context(), "replica-a", 1, now)
	if err != nil || len(selected) != 1 {
		t.Fatalf("claim command: selected=%+v err=%v", selected, err)
	}
	event := domain.Event{EventID: "event-terminal-digest-0001", Digest: "event-digest-a", OccurredAt: now}
	if err := persistence.FinalizeCommandFailureWithEvent(
		t.Context(), command.CommandID, selected[0].Attempts, now, "MEDIA_RETRY_FAILED", event,
	); err != nil {
		t.Fatalf("finalize terminal command: %v", err)
	}
	if err := persistence.FinalizeCommandFailureWithEvent(
		t.Context(), command.CommandID, selected[0].Attempts, now, "MEDIA_RETRY_FAILED", event,
	); err != nil {
		t.Fatalf("idempotent terminal replay: %v", err)
	}
	event.Digest = "event-digest-b"
	if err := persistence.FinalizeCommandFailureWithEvent(
		t.Context(), command.CommandID, selected[0].Attempts, now, "MEDIA_RETRY_FAILED", event,
	); !errors.Is(err, domain.ErrDigestConflict) {
		t.Fatalf("expected terminal event digest conflict, got %v", err)
	}
}

func TestTerminalCommandFailureTreatsMissingCommandAsStateConflict(t *testing.T) {
	t.Parallel()

	persistence := NewMemory()
	event := domain.Event{
		EventID: "event-missing-terminal-command-0001",
		Digest:  "event-missing-terminal-command-digest",
	}
	if err := persistence.FinalizeCommandFailureWithEvent(
		t.Context(), "command-missing-terminal-0001", 1, time.Now().UTC(), "FAILED", event,
	); !errors.Is(err, domain.ErrStateConflict) {
		t.Fatalf("missing terminal command error = %v, want state conflict", err)
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
