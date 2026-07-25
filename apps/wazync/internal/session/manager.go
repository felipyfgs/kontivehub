package session

import (
	"context"
	"errors"
	"sync"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type Connector interface {
	Connect(sessionID string) error
	Disconnect(sessionID string)
}

type contextConnector interface {
	ConnectContext(context.Context, string) error
}

type connectionResetter interface {
	Reset(context.Context, string) error
}

type passiveSetter interface {
	SetPassive(context.Context, string, bool) error
}

type readinessInspector interface {
	Ready(string) bool
}

type pairedSessionInspector interface {
	CanReconnect(string) bool
}

type credentialCleaner interface {
	ForgetSession(context.Context, string) error
}

type lifecycleSignal struct {
	sessionID string
	event     domain.SessionLifecycleEvent
}

var (
	ErrLeaseNotOwned        = errors.New("session lease is not owned by this replica")
	ErrOperationUnsupported = errors.New("session connector operation is unsupported")
)

const maxReconnectAttempts = 5

type Manager struct {
	store      store.Store
	connector  Connector
	replicaID  string
	capacity   int
	leaseTTL   time.Duration
	heartbeat  time.Duration
	now        func() time.Time
	mu         sync.Mutex
	owned      map[string]domain.Lease
	recovering map[string]bool
	lifecycle  chan lifecycleSignal
}

func NewManager(
	persistence store.Store,
	connector Connector,
	replicaID string,
	capacity int,
	leaseTTL, heartbeat time.Duration,
) *Manager {
	return &Manager{
		store: persistence, connector: connector, replicaID: replicaID,
		capacity: capacity, leaseTTL: leaseTTL, heartbeat: heartbeat,
		now: time.Now, owned: make(map[string]domain.Lease),
		recovering: make(map[string]bool), lifecycle: make(chan lifecycleSignal, 128),
	}
}

// NotifyLifecycle coalesces upstream transport signals. Reconcile also checks
// readiness, so a full channel cannot strand a session indefinitely.
func (m *Manager) NotifyLifecycle(sessionID string, event domain.SessionLifecycleEvent) {
	select {
	case m.lifecycle <- lifecycleSignal{sessionID: sessionID, event: event}:
	default:
	}
}

func (m *Manager) Run(ctx context.Context) {
	ticker := time.NewTicker(m.heartbeat)
	defer ticker.Stop()
	_ = m.Reconcile(ctx)
	for {
		select {
		case <-ctx.Done():
			m.Stop(context.Background())
			return
		case signal := <-m.lifecycle:
			m.handleLifecycle(ctx, signal)
		case <-ticker.C:
			_ = m.Reconcile(ctx)
		}
	}
}

func (m *Manager) Reconcile(ctx context.Context) error {
	now := m.now()
	m.mu.Lock()
	ownedBeforeRenew := make(map[string]domain.Lease, len(m.owned))
	for sessionID, lease := range m.owned {
		ownedBeforeRenew[sessionID] = lease
	}
	m.mu.Unlock()

	for sessionID, lease := range ownedBeforeRenew {
		sessionState, getErr := m.store.GetSession(ctx, sessionID)
		if getErr != nil || !sessionState.DesiredConnected {
			m.releaseOwnedLease(ctx, sessionID, lease)
			continue
		}
		renewed, err := m.store.RenewLease(ctx, lease, now, m.leaseTTL)
		if err != nil {
			m.releaseOwnedLease(ctx, sessionID, lease)
			continue
		}
		m.mu.Lock()
		if current, ok := m.owned[sessionID]; ok && current.FencingToken == renewed.FencingToken {
			m.owned[sessionID] = renewed
		}
		m.mu.Unlock()
	}

	m.mu.Lock()
	ownedSnapshot := make(map[string]domain.Lease, len(m.owned))
	for sessionID, lease := range m.owned {
		ownedSnapshot[sessionID] = lease
	}
	m.mu.Unlock()

	if err := m.releaseOrphanLeases(ctx, ownedSnapshot); err != nil {
		return err
	}
	if inspector, ok := m.connector.(readinessInspector); ok {
		for sessionID := range ownedSnapshot {
			session, getErr := m.store.GetSession(ctx, sessionID)
			if getErr != nil || !session.DesiredConnected || inspector.Ready(sessionID) {
				continue
			}
			if credentials, supported := m.connector.(pairedSessionInspector); supported &&
				!credentials.CanReconnect(sessionID) {
				continue
			}
			_ = m.recoverOwned(ctx, sessionID, false)
		}
	}

	leases, err := m.store.ClaimSessions(ctx, m.replicaID, m.capacity, now, m.leaseTTL)
	if err != nil {
		return err
	}
	for _, lease := range leases {
		if err := m.connectClaim(ctx, lease, now); err != nil {
			continue
		}
	}
	return nil
}

func (m *Manager) handleLifecycle(ctx context.Context, signal lifecycleSignal) {
	switch signal.event {
	case domain.SessionLifecycleLoggedOut:
		m.remoteLogout(ctx, signal.sessionID)
	case domain.SessionLifecycleDisconnected:
		_ = m.recoverOwned(ctx, signal.sessionID, false)
	case domain.SessionLifecycleManualLoginReconnect:
		_ = m.recoverOwned(ctx, signal.sessionID, true)
	}
}

func (m *Manager) recoverOwned(ctx context.Context, sessionID string, force bool) error {
	m.mu.Lock()
	if m.recovering[sessionID] {
		m.mu.Unlock()
		return nil
	}
	m.recovering[sessionID] = true
	m.mu.Unlock()
	defer func() {
		m.mu.Lock()
		delete(m.recovering, sessionID)
		m.mu.Unlock()
	}()

	lease, ok := m.Owns(ctx, sessionID)
	if !ok {
		m.connector.Disconnect(sessionID)
		return ErrLeaseNotOwned
	}
	session, err := m.store.GetSession(ctx, sessionID)
	if err != nil {
		return err
	}
	if !session.DesiredConnected {
		m.Release(ctx, sessionID)
		return nil
	}
	if force {
		m.connector.Disconnect(sessionID)
	} else if inspector, supported := m.connector.(readinessInspector); supported && inspector.Ready(sessionID) {
		return nil
	}

	stopRenew := m.renewWhile(ctx, sessionID)
	err = m.connect(ctx, sessionID)
	stopRenew()
	if err != nil {
		m.recordRecoveryFailure(ctx, session)
		m.releaseFailedRecovery(ctx, sessionID, lease)
		return err
	}
	m.mu.Lock()
	if current, stillOwned := m.owned[sessionID]; stillOwned {
		lease = current
	}
	m.mu.Unlock()
	if valid, validErr := m.store.ValidLease(ctx, lease, m.now()); validErr != nil || !valid {
		m.releaseFailedRecovery(ctx, sessionID, lease)
		return ErrLeaseNotOwned
	}
	if err := m.store.SetSessionStatus(ctx, sessionID, domain.SessionConnected, 0, time.Time{}); err != nil {
		m.releaseFailedRecovery(ctx, sessionID, lease)
		return err
	}
	return nil
}

func (m *Manager) releaseFailedRecovery(ctx context.Context, sessionID string, lease domain.Lease) {
	m.connector.Disconnect(sessionID)
	m.mu.Lock()
	if current, ok := m.owned[sessionID]; ok && current.FencingToken == lease.FencingToken {
		delete(m.owned, sessionID)
		lease = current
	}
	m.mu.Unlock()
	_ = m.store.ReleaseLease(ctx, lease)
}

func (m *Manager) remoteLogout(ctx context.Context, sessionID string) {
	if cleaner, ok := m.connector.(credentialCleaner); ok {
		_ = cleaner.ForgetSession(ctx, sessionID)
	}
	if current, err := m.store.GetSession(ctx, sessionID); err == nil {
		current.DesiredConnected = false
		current.Status = domain.SessionDisconnected
		current.ReconnectCount = 0
		current.NextReconnectAt = time.Time{}
		current.UpdatedAt = m.now()
		_ = m.store.UpsertSession(ctx, current)
	}
	m.mu.Lock()
	lease, owned := m.owned[sessionID]
	if owned {
		delete(m.owned, sessionID)
	}
	m.mu.Unlock()
	m.connector.Disconnect(sessionID)
	if owned {
		_ = m.store.ReleaseLease(ctx, lease)
	}
}

func (m *Manager) Owns(ctx context.Context, sessionID string) (domain.Lease, bool) {
	m.mu.Lock()
	lease, ok := m.owned[sessionID]
	m.mu.Unlock()
	if !ok {
		return domain.Lease{}, false
	}
	valid, err := m.store.ValidLease(ctx, lease, m.now())
	return lease, err == nil && valid
}

func (m *Manager) OwnedSessionIDs() []string {
	m.mu.Lock()
	defer m.mu.Unlock()
	result := make([]string, 0, len(m.owned))
	for sessionID := range m.owned {
		result = append(result, sessionID)
	}
	return result
}

func (m *Manager) Stop(ctx context.Context) {
	m.mu.Lock()
	owned := m.owned
	m.owned = make(map[string]domain.Lease)
	m.mu.Unlock()
	for sessionID, lease := range owned {
		m.connector.Disconnect(sessionID)
		_ = m.store.ReleaseLease(ctx, lease)
	}
}

func (m *Manager) Release(ctx context.Context, sessionID string) {
	m.mu.Lock()
	lease, ok := m.owned[sessionID]
	if ok {
		delete(m.owned, sessionID)
	}
	m.mu.Unlock()
	if ok {
		m.connector.Disconnect(sessionID)
		_ = m.store.ReleaseLease(ctx, lease)
	}
}

// AcquireLease claims exactly one session without opening a WhatsApp socket.
// Lifecycle commands such as Logout use it to fence cleanup without reconnecting.
func (m *Manager) AcquireLease(ctx context.Context, sessionID string) error {
	if _, owned := m.Owns(ctx, sessionID); owned {
		return nil
	}
	lease, claimed, err := m.store.ClaimSession(
		ctx, sessionID, m.replicaID, m.capacity, m.now(), m.leaseTTL,
	)
	if err != nil {
		return err
	}
	if !claimed {
		return ErrLeaseNotOwned
	}
	m.mu.Lock()
	m.owned[sessionID] = lease
	m.mu.Unlock()
	return nil
}

// ConnectOwned explicitly connects a session only while this replica owns a
// valid fencing lease. Reconcile remains the normal background entrypoint.
func (m *Manager) ConnectOwned(ctx context.Context, sessionID string) error {
	lease, ok := m.Owns(ctx, sessionID)
	if !ok {
		return ErrLeaseNotOwned
	}
	session, err := m.store.GetSession(ctx, sessionID)
	if err != nil {
		return err
	}
	session.DesiredConnected = true
	if err := m.store.UpsertSession(ctx, session); err != nil {
		return err
	}
	if err := m.connect(ctx, sessionID); err != nil {
		m.recordRecoveryFailure(ctx, session)
		return err
	}
	if valid, err := m.store.ValidLease(ctx, lease, m.now()); err != nil || !valid {
		m.connector.Disconnect(sessionID)
		return ErrLeaseNotOwned
	}
	return m.store.SetSessionStatus(ctx, sessionID, domain.SessionConnected, 0, time.Time{})
}

func (m *Manager) DisconnectOwned(ctx context.Context, sessionID string) error {
	if _, ok := m.Owns(ctx, sessionID); !ok {
		return ErrLeaseNotOwned
	}
	return m.Disconnect(ctx, sessionID)
}

// Disconnect applies the desired state before touching the socket so the
// resulting upstream Disconnected event cannot be misclassified as recovery.
func (m *Manager) Disconnect(ctx context.Context, sessionID string) error {
	session, err := m.store.GetSession(ctx, sessionID)
	if err != nil {
		return err
	}
	session.DesiredConnected = false
	session.Status = domain.SessionDisconnected
	session.ReconnectCount = 0
	session.NextReconnectAt = time.Time{}
	session.UpdatedAt = m.now()
	if err := m.store.UpsertSession(ctx, session); err != nil {
		return err
	}
	m.Release(ctx, sessionID)
	// No valid local lease may still mean a cached, never-connected pairing
	// client. Closing it is local to this replica and cannot affect another one.
	m.connector.Disconnect(sessionID)
	return nil
}

func (m *Manager) Reset(ctx context.Context, sessionID string) error {
	lease, ok := m.Owns(ctx, sessionID)
	if !ok {
		return ErrLeaseNotOwned
	}
	resetter, ok := m.connector.(connectionResetter)
	if !ok {
		return ErrOperationUnsupported
	}
	if err := resetter.Reset(ctx, sessionID); err != nil {
		session, getErr := m.store.GetSession(ctx, sessionID)
		if getErr == nil {
			m.recordRecoveryFailure(ctx, session)
		}
		return err
	}
	if valid, err := m.store.ValidLease(ctx, lease, m.now()); err != nil || !valid {
		m.connector.Disconnect(sessionID)
		return ErrLeaseNotOwned
	}
	return m.store.SetSessionStatus(ctx, sessionID, domain.SessionConnected, 0, time.Time{})
}

func (m *Manager) SetPassive(ctx context.Context, sessionID string, passive bool) error {
	lease, ok := m.Owns(ctx, sessionID)
	if !ok {
		return ErrLeaseNotOwned
	}
	setter, ok := m.connector.(passiveSetter)
	if !ok {
		return ErrOperationUnsupported
	}
	if err := setter.SetPassive(ctx, sessionID, passive); err != nil {
		return err
	}
	if valid, err := m.store.ValidLease(ctx, lease, m.now()); err != nil || !valid {
		m.connector.Disconnect(sessionID)
		return ErrLeaseNotOwned
	}
	return nil
}

func (m *Manager) connectClaim(ctx context.Context, lease domain.Lease, now time.Time) error {
	valid, err := m.store.ValidLease(ctx, lease, now)
	if err != nil || !valid {
		return domain.ErrNotFound
	}
	session, err := m.store.GetSession(ctx, lease.SessionID)
	if err != nil {
		return err
	}
	// Own the lease before any WhatsApp handshake so heartbeat renewal covers
	// connect+ready (which can exceed a single lease TTL without renewal).
	m.mu.Lock()
	m.owned[lease.SessionID] = lease
	m.mu.Unlock()
	if renewed, renewErr := m.store.RenewLease(ctx, lease, now, m.leaseTTL); renewErr == nil {
		m.mu.Lock()
		m.owned[lease.SessionID] = renewed
		lease = renewed
		m.mu.Unlock()
	}

	// Sessão sem credenciais mantém o lease enquanto o worker abre o canal QR.
	// Device existente sempre segue pelo Connect e nunca por GetQRChannel.
	if inspector, supported := m.connector.(pairedSessionInspector); supported &&
		!inspector.CanReconnect(lease.SessionID) {
		return nil
	}
	stopRenew := m.renewWhile(ctx, lease.SessionID)
	err = m.connect(ctx, lease.SessionID)
	stopRenew()
	if err != nil {
		m.recordRecoveryFailure(ctx, session)
		m.mu.Lock()
		delete(m.owned, lease.SessionID)
		m.mu.Unlock()
		_ = m.store.ReleaseLease(ctx, lease)
		return err
	}
	m.mu.Lock()
	if current, ok := m.owned[lease.SessionID]; ok {
		lease = current
	}
	m.mu.Unlock()
	if valid, err := m.store.ValidLease(ctx, lease, m.now()); err != nil || !valid {
		m.connector.Disconnect(lease.SessionID)
		m.mu.Lock()
		delete(m.owned, lease.SessionID)
		m.mu.Unlock()
		_ = m.store.ReleaseLease(ctx, lease)
		return ErrLeaseNotOwned
	}
	if err := m.store.SetSessionStatus(ctx, lease.SessionID, domain.SessionConnected, 0, time.Time{}); err != nil {
		m.connector.Disconnect(lease.SessionID)
		m.mu.Lock()
		delete(m.owned, lease.SessionID)
		m.mu.Unlock()
		_ = m.store.ReleaseLease(ctx, lease)
		return err
	}
	return nil
}

func (m *Manager) releaseOrphanLeases(ctx context.Context, owned map[string]domain.Lease) error {
	orphans, err := m.store.ListReplicaLeases(ctx, m.replicaID, m.now())
	if err != nil {
		return err
	}
	for _, lease := range orphans {
		if _, ok := owned[lease.SessionID]; ok {
			continue
		}
		_ = m.store.ReleaseLease(ctx, lease)
	}
	return nil
}

func (m *Manager) releaseOwnedLease(ctx context.Context, sessionID string, lease domain.Lease) {
	m.connector.Disconnect(sessionID)
	m.mu.Lock()
	if current, ok := m.owned[sessionID]; ok && current.FencingToken == lease.FencingToken {
		delete(m.owned, sessionID)
		lease = current
	}
	m.mu.Unlock()
	_ = m.store.ReleaseLease(ctx, lease)
}

func (m *Manager) recordRecoveryFailure(ctx context.Context, sessionState domain.Session) {
	reconnectCount := sessionState.ReconnectCount + 1
	sessionState.ReconnectCount = reconnectCount
	sessionState.Status = domain.SessionConnecting
	sessionState.NextReconnectAt = m.now().Add(reconnectDelay(reconnectCount))
	if reconnectCount >= maxReconnectAttempts {
		sessionState.Status = domain.SessionDisconnected
		sessionState.DesiredConnected = false
		sessionState.NextReconnectAt = time.Time{}
	}
	sessionState.UpdatedAt = m.now()
	_ = m.store.UpsertSession(ctx, sessionState)
}

func (m *Manager) renewWhile(ctx context.Context, sessionID string) func() {
	done := make(chan struct{})
	var wait sync.WaitGroup
	wait.Add(1)
	go func() {
		defer wait.Done()
		ticker := time.NewTicker(m.heartbeat)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-done:
				return
			case <-ticker.C:
				m.mu.Lock()
				lease, ok := m.owned[sessionID]
				m.mu.Unlock()
				if !ok {
					return
				}
				renewed, err := m.store.RenewLease(ctx, lease, m.now(), m.leaseTTL)
				if err != nil {
					return
				}
				m.mu.Lock()
				if current, stillOwned := m.owned[sessionID]; stillOwned &&
					current.FencingToken == renewed.FencingToken {
					m.owned[sessionID] = renewed
				}
				m.mu.Unlock()
			}
		}
	}()
	return func() {
		close(done)
		wait.Wait()
	}
}

func (m *Manager) connect(ctx context.Context, sessionID string) error {
	if connector, ok := m.connector.(contextConnector); ok {
		return connector.ConnectContext(ctx, sessionID)
	}
	return m.connector.Connect(sessionID)
}

func reconnectDelay(attempt int) time.Duration {
	if attempt < 1 {
		attempt = 1
	}
	delay := time.Second * time.Duration(1<<min(attempt-1, 8))
	return min(delay, 5*time.Minute)
}
