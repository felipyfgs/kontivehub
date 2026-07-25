package session

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type fakeConnector struct {
	mu               sync.Mutex
	failures         int
	connected        map[string]bool
	contextCalls     int
	resetCalls       int
	passiveCalls     int
	passive          bool
	reconnectable    map[string]bool
	forgotten        map[string]bool
	beforeConnectEnd func()
}

func (c *fakeConnector) Connect(sessionID string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.failures > 0 {
		c.failures--
		return fmt.Errorf("temporary connection failure")
	}
	c.connected[sessionID] = true
	return nil
}

func (c *fakeConnector) Disconnect(sessionID string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.connected, sessionID)
}

func (c *fakeConnector) Ready(sessionID string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.connected[sessionID]
}

func (c *fakeConnector) CanReconnect(sessionID string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.reconnectable == nil {
		return true
	}
	return c.reconnectable[sessionID]
}

func (c *fakeConnector) ForgetSession(_ context.Context, sessionID string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.forgotten == nil {
		c.forgotten = make(map[string]bool)
	}
	c.forgotten[sessionID] = true
	if c.reconnectable != nil {
		c.reconnectable[sessionID] = false
	}
	delete(c.connected, sessionID)
	return nil
}

func (c *fakeConnector) ConnectContext(_ context.Context, sessionID string) error {
	c.mu.Lock()
	c.contextCalls++
	hook := c.beforeConnectEnd
	c.mu.Unlock()
	err := c.Connect(sessionID)
	if hook != nil {
		hook()
	}
	return err
}

func (c *fakeConnector) Reset(_ context.Context, sessionID string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if !c.connected[sessionID] {
		return fmt.Errorf("not connected")
	}
	c.resetCalls++
	return nil
}

func (c *fakeConnector) SetPassive(_ context.Context, sessionID string, passive bool) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if !c.connected[sessionID] {
		return fmt.Errorf("not connected")
	}
	c.passiveCalls++
	c.passive = passive
	return nil
}

func TestLeaseContentionAndTakeoverUseHigherFencingToken(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	now := time.Unix(1_785_000_000, 0)
	mustProvision(t, persistence, "session-fencing-0001")

	var leases [2][]domain.Lease
	var wait sync.WaitGroup
	for index, replica := range []string{"replica-a", "replica-b"} {
		wait.Add(1)
		go func(index int, replica string) {
			defer wait.Done()
			leases[index], _ = persistence.ClaimSessions(t.Context(), replica, 1, now, 30*time.Second)
		}(index, replica)
	}
	wait.Wait()
	if len(leases[0])+len(leases[1]) != 1 {
		t.Fatalf("exactly one replica must own the session: %+v", leases)
	}
	var first domain.Lease
	if len(leases[0]) == 1 {
		first = leases[0][0]
	} else {
		first = leases[1][0]
	}

	takeover, err := persistence.ClaimSessions(t.Context(), "replica-c", 1, now.Add(31*time.Second), 30*time.Second)
	if err != nil || len(takeover) != 1 {
		t.Fatalf("takeover failed: leases=%+v err=%v", takeover, err)
	}
	if takeover[0].FencingToken <= first.FencingToken {
		t.Fatalf("fencing token did not advance: first=%d takeover=%d", first.FencingToken, takeover[0].FencingToken)
	}
	valid, _ := persistence.ValidLease(t.Context(), first, now.Add(31*time.Second))
	if valid {
		t.Fatal("expired owner remained valid after takeover")
	}
}

func TestCapacityDistributesFiveThousandLogicalSessionsWithoutDuplicates(t *testing.T) {
	persistence := store.NewMemory()
	now := time.Unix(1_785_000_000, 0)
	for index := range 5_000 {
		mustProvision(t, persistence, fmt.Sprintf("session-load-%04d", index))
	}

	claimed := make(chan domain.Lease, 5_000)
	var wait sync.WaitGroup
	for replica := range 20 {
		wait.Add(1)
		go func(replica int) {
			defer wait.Done()
			leases, err := persistence.ClaimSessions(
				t.Context(), fmt.Sprintf("replica-load-%02d", replica), 250, now, time.Minute,
			)
			if err != nil {
				t.Errorf("claim sessions: %v", err)
				return
			}
			for _, lease := range leases {
				claimed <- lease
			}
		}(replica)
	}
	wait.Wait()
	close(claimed)

	unique := make(map[string]string, 5_000)
	for lease := range claimed {
		if owner, duplicate := unique[lease.SessionID]; duplicate {
			t.Fatalf("session %s claimed by %s and %s", lease.SessionID, owner, lease.ReplicaID)
		}
		unique[lease.SessionID] = lease.ReplicaID
	}
	if len(unique) != 5_000 {
		t.Fatalf("expected 5000 claimed sessions, got %d", len(unique))
	}
}

func TestManagerReconnectsWithBackoffAndChecksFence(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-reconnect-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-reconnect-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare reconnectable session: %v", err)
	}
	connector := &fakeConnector{failures: 1, connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-reconnect", 1, 30*time.Second, 10*time.Second)
	now := time.Unix(1_785_000_000, 0)
	manager.now = func() time.Time { return now }

	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("first reconcile: %v", err)
	}
	session, _ := persistence.GetSession(t.Context(), "session-reconnect-0001")
	if session.Status != domain.SessionConnecting || session.ReconnectCount != 1 {
		t.Fatalf("failed connection did not keep session connecting: %+v", session)
	}

	now = now.Add(2 * time.Second)
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("retry reconcile: %v", err)
	}
	session, _ = persistence.GetSession(t.Context(), "session-reconnect-0001")
	if session.Status != domain.SessionConnected {
		t.Fatalf("session did not reconnect: %+v", session)
	}
	if _, owns := manager.Owns(t.Context(), session.SessionID); !owns {
		t.Fatal("manager connected session without a valid fence")
	}
}

func TestManagerUsesCancelableConnectorAndChecksLeaseAfterConnect(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-stale-connect-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-stale-connect-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare reconnectable session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-stale", 1, 30*time.Second, 10*time.Second)
	manager.now = func() time.Time { return now }
	connector.beforeConnectEnd = func() { now = now.Add(31 * time.Second) }

	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("reconcile stale connect: %v", err)
	}
	connector.mu.Lock()
	connected := connector.connected["session-stale-connect-0001"]
	contextCalls := connector.contextCalls
	connector.mu.Unlock()
	if connected || contextCalls != 1 {
		t.Fatalf("stale owner kept socket or context path was skipped: connected=%v calls=%d", connected, contextCalls)
	}
	if _, owns := manager.Owns(t.Context(), "session-stale-connect-0001"); owns {
		t.Fatal("manager retained ownership after lease expired during connect")
	}
}

func TestOwnedSessionOperationsRequireCurrentFenceAndPersistTransitions(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-owned-ops-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-owned-ops-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-owner", 1, 30*time.Second, 10*time.Second)
	manager.now = func() time.Time { return now }
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim and connect: %v", err)
	}
	if err := manager.Reset(t.Context(), "session-owned-ops-0001"); err != nil {
		t.Fatalf("owned reset: %v", err)
	}
	if err := manager.SetPassive(t.Context(), "session-owned-ops-0001", true); err != nil {
		t.Fatalf("owned passive: %v", err)
	}
	connector.mu.Lock()
	resetCalls, passiveCalls, passive := connector.resetCalls, connector.passiveCalls, connector.passive
	connector.mu.Unlock()
	if resetCalls != 1 || passiveCalls != 1 || !passive {
		t.Fatalf("owned primitives not called: reset=%d passive_calls=%d passive=%v", resetCalls, passiveCalls, passive)
	}
	if err := manager.DisconnectOwned(t.Context(), "session-owned-ops-0001"); err != nil {
		t.Fatalf("owned disconnect: %v", err)
	}
	session, _ := persistence.GetSession(t.Context(), "session-owned-ops-0001")
	if session.Status != domain.SessionDisconnected || session.DesiredConnected {
		t.Fatalf("disconnect transition not persisted: %+v", session)
	}

	now = now.Add(31 * time.Second)
	if err := manager.Reset(t.Context(), "session-owned-ops-0001"); !errors.Is(err, ErrLeaseNotOwned) {
		t.Fatalf("stale reset was not fenced: %v", err)
	}
	connector.mu.Lock()
	resetCalls = connector.resetCalls
	connector.mu.Unlock()
	if resetCalls != 1 {
		t.Fatalf("stale owner caused remote reset: calls=%d", resetCalls)
	}
}

func TestManagerReleasesOrphanSameReplicaLeasesBeforeClaim(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-orphan-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-orphan-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	stale, err := persistence.ClaimSessions(t.Context(), "replica-orphan", 1, now, 30*time.Second)
	if err != nil || len(stale) != 1 {
		t.Fatalf("seed orphan lease: leases=%d err=%v", len(stale), err)
	}
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-orphan", 1, 30*time.Second, 10*time.Second)
	manager.now = func() time.Time { return now }
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("reconcile with orphan lease: %v", err)
	}
	if _, owns := manager.Owns(t.Context(), "session-orphan-0001"); !owns {
		t.Fatal("manager did not reclaim after releasing orphan lease")
	}
	session, _ := persistence.GetSession(t.Context(), "session-orphan-0001")
	if session.Status != domain.SessionConnected {
		t.Fatalf("reclaimed session did not connect: %+v", session)
	}
}

func TestManagerOwnsLeaseBeforeConnectCompletes(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-owned-before-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-owned-before-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	var sawOwnedDuringConnect bool
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-owned-before", 1, 30*time.Second, 10*time.Second)
	manager.now = func() time.Time { return now }
	connector.beforeConnectEnd = func() {
		_, sawOwnedDuringConnect = manager.Owns(t.Context(), "session-owned-before-0001")
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if !sawOwnedDuringConnect {
		t.Fatal("lease was not owned during connect handshake")
	}
}

func TestManagerConnectsProvisionedSessionWhenDeviceIsPersisted(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-provisioned-paired-0001")
	connector := &fakeConnector{
		connected: make(map[string]bool),
		reconnectable: map[string]bool{
			"session-provisioned-paired-0001": true,
		},
	}
	manager := NewManager(persistence, connector, "replica-provisioned-paired", 1, time.Minute, 10*time.Second)

	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("reconcile persisted device: %v", err)
	}
	session, _ := persistence.GetSession(t.Context(), "session-provisioned-paired-0001")
	if session.Status != domain.SessionConnected || !connector.Ready(session.SessionID) {
		t.Fatalf("persisted device waited for QR instead of reconnecting: %+v", session)
	}
}

func TestManagerRecoversOwnedDisconnectedSessionWithoutNewLease(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-runtime-recovery-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-runtime-recovery-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-runtime", 1, time.Minute, 10*time.Second)
	manager.now = func() time.Time { return now }
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("initial connect: %v", err)
	}
	lease, owned := manager.Owns(t.Context(), "session-runtime-recovery-0001")
	if !owned {
		t.Fatal("initial session is not owned")
	}
	connector.Disconnect("session-runtime-recovery-0001")
	manager.handleLifecycle(t.Context(), lifecycleSignal{
		sessionID: "session-runtime-recovery-0001", event: domain.SessionLifecycleDisconnected,
	})
	recoveredLease, recovered := manager.Owns(t.Context(), "session-runtime-recovery-0001")
	if !recovered || recoveredLease.FencingToken != lease.FencingToken ||
		!connector.Ready("session-runtime-recovery-0001") {
		t.Fatalf("runtime recovery changed fence or stayed offline: before=%+v after=%+v ready=%v",
			lease, recoveredLease, connector.Ready("session-runtime-recovery-0001"))
	}
}

func TestManagerDoesNotRecoverAfterLeaseExpires(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-runtime-stale-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-runtime-stale-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	now := time.Unix(1_785_000_000, 0)
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-runtime-stale", 1, 30*time.Second, 10*time.Second)
	manager.now = func() time.Time { return now }
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("initial connect: %v", err)
	}
	connector.Disconnect("session-runtime-stale-0001")
	now = now.Add(31 * time.Second)
	manager.handleLifecycle(t.Context(), lifecycleSignal{
		sessionID: "session-runtime-stale-0001", event: domain.SessionLifecycleDisconnected,
	})
	if connector.Ready("session-runtime-stale-0001") {
		t.Fatal("stale owner reopened the socket")
	}
}

func TestManagerTreatsLoggedOutAsDisconnectedWithoutReconnect(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-runtime-logout-0001")
	if err := persistence.SetSessionStatus(
		t.Context(), "session-runtime-logout-0001", domain.SessionConnecting, 0, time.Time{},
	); err != nil {
		t.Fatalf("prepare session: %v", err)
	}
	connector := &fakeConnector{connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-runtime-logout", 1, time.Minute, 10*time.Second)
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("initial connect: %v", err)
	}
	manager.handleLifecycle(t.Context(), lifecycleSignal{
		sessionID: "session-runtime-logout-0001", event: domain.SessionLifecycleLoggedOut,
	})
	session, _ := persistence.GetSession(t.Context(), "session-runtime-logout-0001")
	if session.Status != domain.SessionDisconnected || session.DesiredConnected ||
		connector.Ready("session-runtime-logout-0001") {
		t.Fatalf("logout was not terminal: %+v ready=%v", session, connector.Ready(session.SessionID))
	}
	if _, owned := manager.Owns(t.Context(), session.SessionID); owned {
		t.Fatal("logged-out session retained its lease")
	}
	connector.mu.Lock()
	forgotten := connector.forgotten[session.SessionID]
	connector.mu.Unlock()
	if !forgotten {
		t.Fatal("remote logout did not clean the selected session credentials")
	}
}

func TestManagerStopsRecoveryAfterBoundedFailures(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	mustProvision(t, persistence, "session-retry-limit-0001")
	connector := &fakeConnector{failures: maxReconnectAttempts, connected: make(map[string]bool)}
	manager := NewManager(persistence, connector, "replica-retry-limit", 1, time.Minute, 10*time.Second)
	now := time.Unix(1_785_000_000, 0)
	manager.now = func() time.Time { return now }

	for range maxReconnectAttempts {
		if err := manager.Reconcile(t.Context()); err != nil {
			t.Fatalf("reconcile failed: %v", err)
		}
		now = now.Add(6 * time.Minute)
	}
	sessionState, _ := persistence.GetSession(t.Context(), "session-retry-limit-0001")
	if sessionState.Status != domain.SessionDisconnected || sessionState.DesiredConnected ||
		sessionState.ReconnectCount != maxReconnectAttempts {
		t.Fatalf("retry limit did not settle disconnected: %+v", sessionState)
	}
	if _, owned := manager.Owns(t.Context(), sessionState.SessionID); owned {
		t.Fatal("retry-exhausted session retained its lease")
	}
}

func mustProvision(t *testing.T, persistence store.Store, sessionID string) {
	t.Helper()
	err := persistence.UpsertSession(context.Background(), domain.Session{
		SessionID: sessionID, Status: domain.SessionConnecting, DesiredConnected: true,
	})
	if err != nil {
		t.Fatalf("provision %s: %v", sessionID, err)
	}
}
