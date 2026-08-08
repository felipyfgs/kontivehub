package command

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type fakeTransport struct {
	connected         bool
	providerMessageID string
	text              string
	media             []byte
	filename          string
	mimeType          string
	sendErr           error
}

func (f *fakeTransport) ConnectContext(context.Context, string) error {
	f.connected = true
	return nil
}
func (f *fakeTransport) Disconnect(string) { f.connected = false }
func (f *fakeTransport) SendTypedMessage(
	_ context.Context,
	_ string,
	payload domain.MessageSendPayload,
	providerMessageID string,
	content []byte,
) error {
	f.text = payload.Text
	if payload.Caption != "" {
		f.text = payload.Caption
	}
	if payload.Media != nil {
		f.filename = payload.Media.Filename
		f.mimeType = payload.Media.MIMEType
	}
	f.media = append([]byte(nil), content...)
	f.providerMessageID = providerMessageID
	return f.sendErr
}
func (f *fakeTransport) Logout(context.Context, string) error { f.connected = false; return nil }

type fakeMediaSource struct {
	content []byte
	err     error
}

type recordingMediaSource struct {
	contents map[string][]byte
	fetched  []string
}

func (s *recordingMediaSource) Fetch(
	_ context.Context,
	_ string,
	sha256 string,
	_ int64,
) ([]byte, error) {
	s.fetched = append(s.fetched, sha256)
	return append([]byte(nil), s.contents[sha256]...), nil
}

type orderedBatchTransport struct {
	fakeTransport
	calls []batchTransportCall
}

type batchTransportCall struct {
	payload           domain.MessageSendPayload
	providerMessageID string
	content           []byte
}

func (f *orderedBatchTransport) SendTypedMessage(
	_ context.Context,
	_ string,
	payload domain.MessageSendPayload,
	providerMessageID string,
	content []byte,
) error {
	f.calls = append(f.calls, batchTransportCall{
		payload: payload, providerMessageID: providerMessageID,
		content: append([]byte(nil), content...),
	})
	return nil
}

type terminalPairer struct{ calls int }

type failingStatusEventStore struct {
	*store.Memory
	markFailedCalls int
}

func (s *failingStatusEventStore) AppendEvent(context.Context, domain.Event) (bool, error) {
	return false, errors.New("status event persistence failed")
}

func (s *failingStatusEventStore) MarkCommandFailed(
	ctx context.Context,
	commandID string,
	availableAt time.Time,
	code string,
	terminal bool,
) error {
	s.markFailedCalls++
	return s.Memory.MarkCommandFailed(ctx, commandID, availableAt, code, terminal)
}

func (p *terminalPairer) StartPairing(context.Context, string) (<-chan domain.PairingUpdate, error) {
	p.calls++
	return nil, domain.ErrSessionAlreadyPaired
}

type lifecycleTransport struct {
	fakeTransport
	credentials  bool
	connectCalls int
	logoutCalls  int
}

func (f *lifecycleTransport) ConnectContext(context.Context, string) error {
	f.connectCalls++
	f.connected = true
	return nil
}
func (f *lifecycleTransport) CanReconnect(string) bool   { return f.credentials }
func (f *lifecycleTransport) HasCredentials(string) bool { return f.credentials }
func (f *lifecycleTransport) ForgetSession(context.Context, string) error {
	f.credentials = false
	f.connected = false
	return nil
}
func (f *lifecycleTransport) Logout(context.Context, string) error {
	f.logoutCalls++
	f.credentials = false
	f.connected = false
	return nil
}

type controllablePairer struct {
	calls   int
	updates chan domain.PairingUpdate
}

func (p *controllablePairer) StartPairing(ctx context.Context, _ string) (<-chan domain.PairingUpdate, error) {
	p.calls++
	out := make(chan domain.PairingUpdate, 4)
	go func() {
		defer close(out)
		for {
			select {
			case <-ctx.Done():
				return
			case update := <-p.updates:
				out <- update
			}
		}
	}()
	return out, nil
}

func (f fakeMediaSource) Fetch(context.Context, string, string, int64) ([]byte, error) {
	return append([]byte(nil), f.content...), f.err
}

type fakeTypedTransport struct {
	fakeTransport
	calls   int
	payload domain.MessageSendPayload
}

type fakeActionTransport struct {
	fakeTransport
	action    string
	target    string
	actionErr error
}

type failingActionResultStore struct {
	*store.Memory
	finalizeCalls int
}

func (s *failingActionResultStore) FinalizeCommandProcessedWithEvent(
	context.Context, string, int, time.Time, domain.Event,
) error {
	s.finalizeCalls++
	return errors.New("action result persistence failed")
}

type fakePresenceTransport struct {
	fakeTransport
	presence string
}

func (f *fakePresenceTransport) SetPresence(
	_ context.Context, _ string, payload domain.PresencePayload,
) error {
	f.presence = payload.Presence
	return nil
}

func (f *fakePresenceTransport) SubscribeContactPresence(
	_ context.Context, _ string, payload domain.ContactPresencePayload,
) error {
	f.presence = "SUBSCRIBE:" + payload.To
	return nil
}

func (f *fakePresenceTransport) SetChatPresence(
	_ context.Context, _ string, payload domain.ChatPresencePayload,
) error {
	f.presence = payload.Presence
	return nil
}

func (f *fakeActionTransport) EditMessage(
	_ context.Context, _ string, payload domain.MessageEditPayload, _ string,
) error {
	f.action, f.target = "edit", payload.TargetMessageID
	return f.actionErr
}

func (f *fakeActionTransport) RevokeMessage(
	_ context.Context, _ string, payload domain.MessageTargetPayload, _ string,
) error {
	f.action, f.target = "revoke", payload.TargetMessageID
	return f.actionErr
}

func (f *fakeActionTransport) ReactMessage(
	_ context.Context, _ string, payload domain.MessageReactionPayload, _ string,
) error {
	f.action, f.target = "react:"+payload.Emoji, payload.TargetMessageID
	return f.actionErr
}

func (f *fakeActionTransport) VotePoll(
	_ context.Context, _ string, payload domain.PollVotePayload, _ string,
) error {
	f.action, f.target = "vote", payload.TargetMessageID
	return nil
}

func (f *fakeActionTransport) MarkMessage(
	_ context.Context, _ string, payload domain.MessageMarkPayload,
) error {
	f.action, f.target = "mark:"+payload.Receipt, payload.MessageIDs[0]
	return nil
}

func (f *fakeActionTransport) SetChatDisappearing(
	_ context.Context, _ string, payload domain.DisappearingPayload,
) error {
	f.action = "disappearing"
	return nil
}

func (f *fakeActionTransport) RequestUnavailableMessage(
	_ context.Context, _ string, payload domain.MessageTargetPayload,
) error {
	f.action, f.target = "unavailable", payload.TargetMessageID
	return nil
}

func (f *fakeTypedTransport) SendTypedMessage(
	_ context.Context,
	_ string,
	payload domain.MessageSendPayload,
	providerMessageID string,
	_ []byte,
) error {
	f.calls++
	f.payload = payload
	f.providerMessageID = providerMessageID
	return nil
}

func TestWorkerProvisionsAndSendsOnlyWithOwnedLease(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTransport{}
	manager := session.NewManager(persistence, transport, "replica-worker", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-worker")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }

	provisionPayload, _ := json.Marshal(map[string]bool{"desired_connected": true})
	_, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-provision-0001", SessionID: "session-worker-0001",
		Type: domain.CommandProvisionSession, Payload: provisionPayload, Digest: "provision-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept provision: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process provision: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}

	messagePayload, _ := json.Marshal(map[string]string{
		"to": "+5511999991234", "kind": "TEXT", "text": "Olá",
	})
	_, err = persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-message-0001", SessionID: "session-worker-0001",
		Type: domain.CommandSendMessage, ProviderMessageID: "provider-message-0001",
		Payload: messagePayload, Digest: "message-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept message: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process message: %v", err)
	}
	if transport.providerMessageID != "provider-message-0001" || transport.text != "Olá" {
		t.Fatalf("transport identity changed: id=%q text=%q", transport.providerMessageID, transport.text)
	}
	metrics, _ := persistence.Metrics(t.Context())
	if metrics.PendingCommands != 0 || metrics.PendingEvents != 1 {
		t.Fatalf("unexpected ledger state: %+v", metrics)
	}
}

func TestWorkerDoesNotRetryDeterministicPairingFailure(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTransport{}
	manager := session.NewManager(persistence, transport, "replica-pair-terminal", 10, time.Minute, 10*time.Second)
	pairer := &terminalPairer{}
	coordinator := session.NewPairingCoordinator(persistence, pairer, nil)
	worker := New(persistence, manager, coordinator, transport, "replica-pair-terminal")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	payload, _ := json.Marshal(map[string]any{})
	_, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-pair-terminal-0001",
		SessionID: "session-pair-terminal-0001", Type: domain.CommandPairSession,
		Payload: payload, Digest: "pair-terminal-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept pairing command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process terminal pairing command: %v", err)
	}
	now = now.Add(10 * time.Minute)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("repeat worker tick: %v", err)
	}
	if pairer.calls != 1 {
		t.Fatalf("terminal pairing command retried: calls=%d", pairer.calls)
	}
	if !transport.connected {
		t.Fatal("stored device was not routed to fenced reconnect")
	}
}

func TestWorkerDoesNotFinalizeTerminalSendWhenUnknownStatusEventFails(t *testing.T) {
	t.Parallel()
	persistence := &failingStatusEventStore{Memory: store.NewMemory()}
	transport := &fakeTransport{sendErr: errors.New("provider send failed")}
	manager := session.NewManager(persistence, transport, "replica-send-terminal", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-send-terminal")
	worker.maxAttempts = 1
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-send-terminal-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision terminal send session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim terminal send session: %v", err)
	}
	payload, _ := json.Marshal(map[string]string{
		"to": "+5511999991234", "kind": "TEXT", "text": "Olá",
	})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-send-terminal-0001",
		SessionID: "session-send-terminal-0001", Type: domain.CommandSendMessage,
		ProviderMessageID: "provider-send-terminal-0001", Payload: payload,
		Digest: "send-terminal-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept terminal send: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err == nil {
		t.Fatal("terminal send succeeded despite failed UNKNOWN status event")
	}
	if persistence.markFailedCalls != 0 {
		t.Fatalf("terminal send was finalized before UNKNOWN status persisted: calls=%d", persistence.markFailedCalls)
	}
}

func TestWorkerResumesDesiredPairingWithoutAnotherConnectCommand(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &lifecycleTransport{}
	manager := session.NewManager(persistence, transport, "replica-pair-resume", 1, time.Minute, 10*time.Second)
	state := domain.Session{
		SessionID: "session-pair-resume-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}
	if err := persistence.UpsertSession(t.Context(), state); err != nil {
		t.Fatalf("seed connecting session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim connecting session: %v", err)
	}
	pairer := &controllablePairer{updates: make(chan domain.PairingUpdate)}
	coordinator := session.NewPairingCoordinator(persistence, pairer, nil)
	worker := New(persistence, manager, coordinator, transport, "replica-pair-resume")
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("resume pairing: %v", err)
	}
	if pairer.calls != 1 || !coordinator.Active("session-pair-resume-0001") {
		t.Fatalf("pairing was not resumed: calls=%d active=%v", pairer.calls, coordinator.Active("session-pair-resume-0001"))
	}
	state.DesiredConnected = false
	if err := persistence.UpsertSession(t.Context(), state); err != nil {
		t.Fatalf("stop resumed pairing: %v", err)
	}
	coordinator.Cancel("session-pair-resume-0001")
}

func TestWorkerConnectDisconnectAndLogoutUseCredentialAwareLifecycle(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &lifecycleTransport{credentials: true}
	manager := session.NewManager(persistence, transport, "replica-lifecycle", 2, time.Minute, 10*time.Second)
	pairer := &controllablePairer{updates: make(chan domain.PairingUpdate, 1)}
	coordinator := session.NewPairingCoordinator(persistence, pairer, nil)
	worker := New(persistence, manager, coordinator, transport, "replica-lifecycle")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-lifecycle-0001", Status: domain.SessionDisconnected,
	}); err != nil {
		t.Fatalf("seed session: %v", err)
	}

	acceptLifecycleCommand(t, persistence, "command-connect-life-0001", "session-lifecycle-0001", domain.CommandConnectSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("connect: %v", err)
	}
	state, _ := persistence.GetSession(t.Context(), "session-lifecycle-0001")
	if state.Status != domain.SessionConnected || !transport.connected || pairer.calls != 0 {
		t.Fatalf("credentialed connect generated pairing or stayed offline: state=%+v pairings=%d", state, pairer.calls)
	}

	now = now.Add(time.Second)
	acceptLifecycleCommand(t, persistence, "command-disconn-life-001", state.SessionID, domain.CommandDisconnectSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("disconnect: %v", err)
	}
	state, _ = persistence.GetSession(t.Context(), state.SessionID)
	if state.Status != domain.SessionDisconnected || state.DesiredConnected || !transport.credentials || transport.connected {
		t.Fatalf("disconnect did not preserve credentials: %+v credentials=%v connected=%v", state, transport.credentials, transport.connected)
	}
	if _, owned := manager.Owns(t.Context(), state.SessionID); owned {
		t.Fatal("disconnect retained the lease")
	}
	connectCallsBeforeLogout := transport.connectCalls

	now = now.Add(time.Second)
	acceptLifecycleCommand(t, persistence, "command-logout-life-0001", state.SessionID, domain.CommandLogoutSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("logout: %v", err)
	}
	state, _ = persistence.GetSession(t.Context(), state.SessionID)
	if state.Status != domain.SessionDisconnected || state.DesiredConnected || transport.credentials || transport.logoutCalls != 1 {
		t.Fatalf("logout did not remove credentials: state=%+v credentials=%v calls=%d", state, transport.credentials, transport.logoutCalls)
	}
	if transport.connectCalls != connectCallsBeforeLogout {
		t.Fatalf("logout reconnected the socket before cleanup: before=%d after=%d", connectCallsBeforeLogout, transport.connectCalls)
	}
}

func TestWorkerConnectWithoutCredentialsStartsSinglePairing(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &lifecycleTransport{}
	manager := session.NewManager(persistence, transport, "replica-new-device", 1, time.Minute, 10*time.Second)
	pairer := &controllablePairer{updates: make(chan domain.PairingUpdate, 1)}
	coordinator := session.NewPairingCoordinator(persistence, pairer, nil)
	worker := New(persistence, manager, coordinator, transport, "replica-new-device")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }

	acceptLifecycleCommand(t, persistence, "command-connect-new-0001", "session-new-device-0001", domain.CommandConnectSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("connect new device: %v", err)
	}
	state, _ := persistence.GetSession(t.Context(), "session-new-device-0001")
	if state.Status != domain.SessionConnecting || pairer.calls != 1 || transport.connected {
		t.Fatalf("new device did not enter one pairing: state=%+v calls=%d connected=%v", state, pairer.calls, transport.connected)
	}

	now = now.Add(time.Second)
	acceptLifecycleCommand(t, persistence, "command-connect-new-0002", state.SessionID, domain.CommandConnectSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("idempotent connect: %v", err)
	}
	if pairer.calls != 1 {
		t.Fatalf("duplicate connect opened another QR channel: %d", pairer.calls)
	}
	now = now.Add(time.Second)
	acceptLifecycleCommand(t, persistence, "command-disconn-new-001", state.SessionID, domain.CommandDisconnectSession, now)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("cancel pairing: %v", err)
	}
}

func acceptLifecycleCommand(
	t *testing.T,
	persistence *store.Memory,
	commandID, sessionID string,
	commandType domain.CommandType,
	at time.Time,
) {
	t.Helper()
	payload, _ := json.Marshal(map[string]any{})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: commandID, SessionID: sessionID,
		Type: commandType, Payload: payload, Digest: commandID, AcceptedAt: at,
	}); err != nil {
		t.Fatalf("accept %s: %v", commandType, err)
	}
}

func TestWorkerFetchesAndSendsDocumentForMediaCommand(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTransport{}
	manager := session.NewManager(persistence, transport, "replica-media", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-media").WithMediaSource(
		fakeMediaSource{content: []byte("%PDF-document")},
	)
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-media-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}
	payload, _ := json.Marshal(map[string]any{
		"to": "+5511999991234", "kind": "DOCUMENT", "caption": "Segue a guia",
		"media": map[string]any{
			"filename": "guia.pdf", "mime_type": "application/pdf", "size_bytes": 13,
			"sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		},
	})
	_, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-media-0001", SessionID: "session-media-0001",
		Type: domain.CommandSendMessage, ProviderMessageID: "provider-media-0001",
		Payload: payload, Digest: "media-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept media command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process media command: %v", err)
	}
	if transport.filename != "guia.pdf" || transport.mimeType != "application/pdf" || string(transport.media) != "%PDF-document" {
		t.Fatalf("media was not sent: filename=%q mime=%q bytes=%q", transport.filename, transport.mimeType, transport.media)
	}
}

func TestWorkerPublishesTerminalFailureWhenOutboundMediaCannotBeFetched(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTransport{}
	manager := session.NewManager(persistence, transport, "replica-media-failure", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-media-failure").WithMediaSource(
		fakeMediaSource{err: errors.New("private object unreadable")},
	)
	worker.maxAttempts = 1
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-media-failure-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim session: %v", err)
	}
	payload, _ := json.Marshal(map[string]any{
		"to": "+5511999991234", "kind": "DOCUMENT",
		"media": map[string]any{
			"filename": "guia.pdf", "mime_type": "application/pdf", "size_bytes": 13,
			"sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		},
	})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-media-failure-0001", SessionID: "session-media-failure-0001",
		Type: domain.CommandSendMessage, ProviderMessageID: "provider-media-failure-0001",
		Payload: payload, Digest: "media-failure-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept media command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process media command: %v", err)
	}
	events, err := persistence.NextEvents(t.Context(), 1, now)
	if err != nil || len(events) != 1 {
		t.Fatalf("terminal media event missing: events=%+v err=%v", events, err)
	}
	var result map[string]string
	if err := json.Unmarshal(events[0].Event.Payload, &result); err != nil {
		t.Fatalf("decode terminal media event: %v", err)
	}
	if result["status"] != "FAILED" || result["error_code"] != "OUTBOUND_MEDIA_UNAVAILABLE" {
		t.Fatalf("terminal media result = %#v", result)
	}
	if transport.providerMessageID != "" {
		t.Fatalf("transport was called without media: %q", transport.providerMessageID)
	}
}

func TestWorkerRejectsPTVBeforeMediaFetch(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTransport{}
	manager := session.NewManager(persistence, transport, "replica-ptv-no-fetch", 10, time.Minute, 10*time.Second)
	source := &recordingMediaSource{contents: map[string][]byte{
		"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa": []byte("video"),
	}}
	worker := New(persistence, manager, nil, transport, "replica-ptv-no-fetch").WithMediaSource(source)
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-ptv-no-fetch-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision PTV session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim PTV session: %v", err)
	}
	payload, _ := json.Marshal(domain.MessageSendPayload{
		To: "+5511999991234", Kind: domain.MessageVideo,
		Media: &domain.MediaReference{
			Filename: "video-ptv.mp4", MIMEType: "video/mp4",
			SHA256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", PTV: true,
		},
	})
	command := domain.Command{
		ContractVersion: "v1", CommandID: "command-ptv-no-fetch-0001", SessionID: "session-ptv-no-fetch-0001",
		Type: domain.CommandSendMessage, ProviderMessageID: "provider-ptv-no-fetch-0001",
		Payload: payload, Digest: "ptv-no-fetch-digest", AcceptedAt: now,
	}
	if err := worker.process(t.Context(), command, 1); err == nil || err.Error() != "PTV builder is unavailable" {
		t.Fatalf("PTV command must fail before fetch: err=%v", err)
	}
	if len(source.fetched) != 0 || transport.providerMessageID != "" {
		t.Fatalf("PTV reached media or transport: fetched=%d transport_id=%q", len(source.fetched), transport.providerMessageID)
	}
}

func TestWorkerSendsBatchItemsInCorrelatedPositionOrder(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &orderedBatchTransport{}
	manager := session.NewManager(persistence, transport, "replica-batch-order", 10, time.Minute, 10*time.Second)
	sha1 := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	sha2 := "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	sha3 := "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
	source := &recordingMediaSource{contents: map[string][]byte{
		sha1: []byte("primeiro"),
		sha2: []byte("segundo"),
		sha3: []byte("terceiro"),
	}}
	worker := New(persistence, manager, nil, transport, "replica-batch-order").WithMediaSource(source)
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-batch-order-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision batch session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim batch session: %v", err)
	}

	media := func(name, mime, sha string) domain.MessageSendPayload {
		return domain.MessageSendPayload{
			To: "+5511999991234", Kind: domain.MessageDocument,
			Media: &domain.MediaReference{Filename: name, MIMEType: mime, SHA256: sha},
		}
	}
	batch := domain.MessageBatchPayload{
		BatchID: "batch-order-0001", Size: 3,
		Items: []domain.MessageBatchItemPayload{
			{
				BatchID: "batch-order-0001", Position: 2, Size: 3,
				ProviderMessageID: "provider-batch-0003", Message: media("terceiro.pdf", "application/pdf", sha3),
			},
			{
				BatchID: "batch-order-0001", Position: 0, Size: 3,
				ProviderMessageID: "provider-batch-0001", Message: media("primeiro.pdf", "application/pdf", sha1),
			},
			{
				BatchID: "batch-order-0001", Position: 1, Size: 3,
				ProviderMessageID: "provider-batch-0002", Message: media("segundo.pdf", "application/pdf", sha2),
			},
		},
	}
	payload, _ := json.Marshal(batch)
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-batch-order-0001", SessionID: "session-batch-order-0001",
		Type: domain.CommandSendMessageBatch, Payload: payload, Digest: "batch-order-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept batch command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process batch command: %v", err)
	}

	wantIDs := []string{"provider-batch-0001", "provider-batch-0002", "provider-batch-0003"}
	if len(transport.calls) != len(wantIDs) {
		t.Fatalf("batch transport calls = %d, want %d", len(transport.calls), len(wantIDs))
	}
	for index, call := range transport.calls {
		if call.providerMessageID != wantIDs[index] {
			t.Fatalf("batch transport order[%d] = %q, want %q", index, call.providerMessageID, wantIDs[index])
		}
		if call.payload.To != "+5511999991234" {
			t.Fatalf("batch item %q changed recipient: %+v", call.providerMessageID, call.payload)
		}
	}
	if len(source.fetched) != len(wantIDs) {
		t.Fatalf("batch media fetches = %d, want %d", len(source.fetched), len(wantIDs))
	}

	for index, position := range []int{0, 1, 2} {
		state, err := persistence.GetMessageBatchItemState(t.Context(), "command-batch-order-0001", position)
		if err != nil {
			t.Fatalf("read batch item state[%d]: %v", position, err)
		}
		if state.ProviderMessageID != wantIDs[index] || state.Status != domain.MessageBatchItemSent {
			t.Fatalf("batch item state[%d] = %#v, want provider %q status %q",
				position, state, wantIDs[index], domain.MessageBatchItemSent)
		}
	}

	callsBeforeSecondTick := len(transport.calls)
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("second worker tick: %v", err)
	}
	if len(transport.calls) != callsBeforeSecondTick {
		t.Fatalf("batch command sent again: calls before=%d after=%d", callsBeforeSecondTick, len(transport.calls))
	}
	for index, call := range transport.calls {
		sha := []string{sha1, sha2, sha3}[index]
		if !bytes.Equal(call.content, source.contents[sha]) {
			t.Fatalf("batch transport content[%d] = %q, want %q", index, call.content, source.contents[sha])
		}
	}
}

func TestWorkerDispatchesTypedMessageExactlyOnceForDuplicateCommand(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeTypedTransport{}
	manager := session.NewManager(persistence, transport, "replica-typed", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-typed")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-typed-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision typed session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim typed session: %v", err)
	}
	payload, _ := json.Marshal(domain.MessageSendPayload{
		To: "+5511999991234", Kind: domain.MessageLocation,
		Location: &domain.LocationPayload{Latitude: -23.55, Longitude: -46.63, Name: "São Paulo"},
	})
	command := domain.Command{
		ContractVersion: "v1", CommandID: "command-typed-0001", SessionID: "session-typed-0001",
		Type: domain.CommandSendMessage, ProviderMessageID: "provider-typed-0001",
		Payload: payload, Digest: "typed-digest", AcceptedAt: now,
	}
	if duplicate, err := persistence.AcceptCommand(t.Context(), command); err != nil || duplicate {
		t.Fatalf("accept typed command: duplicate=%v err=%v", duplicate, err)
	}
	if duplicate, err := persistence.AcceptCommand(t.Context(), command); err != nil || !duplicate {
		t.Fatalf("same typed command must deduplicate: duplicate=%v err=%v", duplicate, err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process typed command: %v", err)
	}
	if transport.calls != 1 || transport.payload.Location == nil ||
		transport.providerMessageID != "provider-typed-0001" {
		t.Fatalf("typed command dispatch changed: calls=%d payload=%+v id=%q",
			transport.calls, transport.payload, transport.providerMessageID)
	}
}

func TestWorkerRoutesMessageActionThroughOwnedSession(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeActionTransport{}
	manager := session.NewManager(persistence, transport, "replica-action", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-action")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-action-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision action session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim action session: %v", err)
	}
	payload, _ := json.Marshal(domain.MessageReactionPayload{
		MessageTargetPayload: domain.MessageTargetPayload{
			To: "+5511999991234", TargetMessageID: "target-message-0001", Sender: "+5511999991234",
		},
		Emoji: "✅",
	})
	_, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-action-0001", SessionID: "session-action-0001",
		Type: domain.CommandReactMessage, ProviderMessageID: "provider-action-0001",
		Payload: payload, Digest: "action-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept action command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process action command: %v", err)
	}
	if transport.action != "react:✅" || transport.target != "target-message-0001" {
		t.Fatalf("action route changed: action=%q target=%q", transport.action, transport.target)
	}
	events, err := persistence.NextEvents(t.Context(), 1, now)
	if err != nil || len(events) != 1 {
		t.Fatalf("action result was not persisted: events=%+v err=%v", events, err)
	}
	if events[0].Event.Type != domain.EventMessageActionResult {
		t.Fatalf("action result type = %q", events[0].Event.Type)
	}
	var result map[string]string
	if err := json.Unmarshal(events[0].Event.Payload, &result); err != nil {
		t.Fatalf("decode action result: %v", err)
	}
	want := map[string]string{
		"command_id": "command-action-0001", "action": "REACTION", "status": "SUCCEEDED",
		"provider_message_id": "provider-action-0001", "target_message_id": "target-message-0001",
	}
	if !reflect.DeepEqual(result, want) {
		t.Fatalf("action result payload = %#v, want %#v", result, want)
	}
}

func TestWorkerFinalizesTerminalActionFailureWithBoundedResult(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakeActionTransport{actionErr: errors.New("provider details must not leak")}
	manager := session.NewManager(persistence, transport, "replica-action-terminal", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-action-terminal")
	worker.maxAttempts = 1
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-action-terminal-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision action session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim action session: %v", err)
	}
	payload, _ := json.Marshal(domain.MessageTargetPayload{To: "+5511999991234", TargetMessageID: "target-action-terminal-0001"})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-action-terminal-0001", SessionID: "session-action-terminal-0001",
		Type: domain.CommandRevokeMessage, ProviderMessageID: "provider-action-terminal-0001",
		Payload: payload, Digest: "action-terminal-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept terminal action: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process terminal action: %v", err)
	}
	events, err := persistence.NextEvents(t.Context(), 1, now)
	if err != nil || len(events) != 1 {
		t.Fatalf("terminal result was not persisted: events=%+v err=%v", events, err)
	}
	var result map[string]string
	if err := json.Unmarshal(events[0].Event.Payload, &result); err != nil {
		t.Fatalf("decode terminal result: %v", err)
	}
	if result["action"] != "REVOKE" || result["status"] != "FAILED" || result["error_code"] != "ACTION_RETRY_EXHAUSTED" {
		t.Fatalf("terminal result lost bounded fields: %#v", result)
	}
	if len(result) != 6 {
		t.Fatalf("terminal result leaked unallowlisted fields: %#v", result)
	}
}

func TestWorkerDoesNotMarkActionProcessedWhenResultPersistenceFails(t *testing.T) {
	t.Parallel()
	persistence := &failingActionResultStore{Memory: store.NewMemory()}
	transport := &fakeActionTransport{}
	manager := session.NewManager(persistence, transport, "replica-action-event-failure", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-action-event-failure")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-action-event-failure-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision action session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim action session: %v", err)
	}
	payload, _ := json.Marshal(domain.MessageEditPayload{
		MessageTargetPayload: domain.MessageTargetPayload{To: "+5511999991234", TargetMessageID: "target-action-event-failure-0001"},
		Text:                 "edited",
	})
	if _, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-action-event-failure-0001", SessionID: "session-action-event-failure-0001",
		Type: domain.CommandEditMessage, ProviderMessageID: "provider-action-event-failure-0001",
		Payload: payload, Digest: "action-event-failure-digest", AcceptedAt: now,
	}); err != nil {
		t.Fatalf("accept action: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err == nil {
		t.Fatal("processed action despite failed result persistence")
	}
	if persistence.finalizeCalls != 1 {
		t.Fatalf("result finalizer calls = %d, want 1", persistence.finalizeCalls)
	}
	metrics, err := persistence.Metrics(t.Context())
	if err != nil || metrics.PendingCommands != 1 || metrics.PendingEvents != 0 {
		t.Fatalf("action command was finalized without result: metrics=%+v err=%v", metrics, err)
	}
}

func TestWorkerProcessesPresenceWithoutCreatingDurableMessageEvent(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	transport := &fakePresenceTransport{}
	manager := session.NewManager(persistence, transport, "replica-presence", 10, time.Minute, 10*time.Second)
	worker := New(persistence, manager, nil, transport, "replica-presence")
	now := time.Now().UTC()
	worker.now = func() time.Time { return now }
	if err := persistence.UpsertSession(t.Context(), domain.Session{
		SessionID: "session-presence-0001", Status: domain.SessionConnecting, DesiredConnected: true,
	}); err != nil {
		t.Fatalf("provision presence session: %v", err)
	}
	if err := manager.Reconcile(t.Context()); err != nil {
		t.Fatalf("claim presence session: %v", err)
	}
	payload, _ := json.Marshal(domain.ChatPresencePayload{
		To: "+5511999991234", Presence: "COMPOSING", Media: "TEXT",
	})
	_, err := persistence.AcceptCommand(t.Context(), domain.Command{
		ContractVersion: "v1", CommandID: "command-presence-0001", SessionID: "session-presence-0001",
		Type: domain.CommandSetChatPresence, Payload: payload, Digest: "presence-digest", AcceptedAt: now,
	})
	if err != nil {
		t.Fatalf("accept presence command: %v", err)
	}
	if err := worker.ProcessOnce(t.Context()); err != nil {
		t.Fatalf("process presence command: %v", err)
	}
	metrics, err := persistence.Metrics(t.Context())
	if err != nil || metrics.PendingEvents != 0 || transport.presence != "COMPOSING" {
		t.Fatalf("presence became durable product event: metrics=%+v value=%q err=%v", metrics, transport.presence, err)
	}
}
