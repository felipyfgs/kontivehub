package command

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"log/slog"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

var errLeaseUnavailable = errors.New("session lease unavailable")

type Transport interface {
	SendText(context.Context, string, string, string, string) error
	SendMedia(context.Context, string, string, string, string, string, string, []byte) error
	Logout(context.Context, string) error
}

type SessionTransport interface {
	HasCredentials(string) bool
	ForgetSession(context.Context, string) error
}

type TypedTransport interface {
	SendTypedMessage(context.Context, string, domain.MessageSendPayload, string, []byte) error
}

type ActionTransport interface {
	EditMessage(context.Context, string, domain.MessageEditPayload, string) error
	RevokeMessage(context.Context, string, domain.MessageTargetPayload, string) error
	ReactMessage(context.Context, string, domain.MessageReactionPayload, string) error
	VotePoll(context.Context, string, domain.PollVotePayload, string) error
	MarkMessage(context.Context, string, domain.MessageMarkPayload) error
	SetChatDisappearing(context.Context, string, domain.DisappearingPayload) error
	RequestUnavailableMessage(context.Context, string, domain.MessageTargetPayload) error
}

type PresenceTransport interface {
	SetPresence(context.Context, string, domain.PresencePayload) error
	SubscribeContactPresence(context.Context, string, domain.ContactPresencePayload) error
	SetChatPresence(context.Context, string, domain.ChatPresencePayload) error
}

type AccountPolicyTransport interface {
	UpdateBlocklist(context.Context, string, domain.BlocklistUpdatePayload) error
	UpdatePrivacy(context.Context, string, domain.PrivacyUpdatePayload) error
	SetDefaultDisappearing(context.Context, string, domain.DefaultDisappearingPayload) error
	UpdateChatState(context.Context, string, domain.ChatStatePayload) error
}

type RecoveryTransport interface {
	RequestHistorySync(context.Context, string, domain.HistorySyncPayload) error
	RetryMedia(context.Context, string, domain.MediaRetryPayload) error
}

type MediaFetcher interface {
	Fetch(context.Context, string, string, int64) ([]byte, error)
}

type Worker struct {
	store       store.Store
	sessions    *session.Manager
	pairing     *session.PairingCoordinator
	transport   Transport
	replicaID   string
	batchSize   int
	maxAttempts int
	media       MediaFetcher
	now         func() time.Time
}

func (w *Worker) WithMediaFetcher(fetcher MediaFetcher) *Worker {
	w.media = fetcher
	return w
}

func New(
	persistence store.Store,
	sessions *session.Manager,
	pairing *session.PairingCoordinator,
	transport Transport,
	replicaID string,
) *Worker {
	return &Worker{
		store: persistence, sessions: sessions, pairing: pairing, transport: transport,
		replicaID: replicaID, batchSize: 25, maxAttempts: 10, now: time.Now,
	}
}

func (w *Worker) Run(ctx context.Context, every time.Duration) {
	ticker := time.NewTicker(every)
	defer ticker.Stop()
	for {
		if err := w.ProcessOnce(ctx); err != nil {
			slog.Error("command worker tick failed", "error", err.Error())
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

func (w *Worker) ProcessOnce(ctx context.Context) error {
	now := w.now().UTC()
	commands, err := w.store.NextCommands(ctx, w.replicaID, w.batchSize, now)
	if err != nil {
		return err
	}
	for _, pending := range commands {
		err := w.process(ctx, pending.Command)
		if err == nil {
			if err := w.store.MarkCommandProcessed(ctx, pending.Command.CommandID, now); err != nil {
				return err
			}
			continue
		}
		terminal := errors.Is(err, session.ErrPairingTerminal) || pending.Attempts >= w.maxAttempts
		if terminal && pending.Command.Type == domain.CommandSendMessage {
			_ = w.appendStatusEvent(ctx, pending.Command, "UNKNOWN", now)
		}
		slog.Warn("command execution failed",
			"command_id", pending.Command.CommandID,
			"type", pending.Command.Type,
			"session_id", pending.Command.SessionID,
			"attempt", pending.Attempts,
			"error", err.Error(),
		)
		if err := w.store.MarkCommandFailed(
			ctx,
			pending.Command.CommandID,
			now.Add(retryDelay(pending.Attempts)),
			commandErrorCode(err),
			terminal,
		); err != nil {
			return err
		}
	}
	return w.reconcilePairingSessions(ctx)
}

func (w *Worker) reconcilePairingSessions(ctx context.Context) error {
	if w.pairing == nil {
		return nil
	}
	transport, ok := w.transport.(SessionTransport)
	if !ok {
		return nil
	}
	for _, sessionID := range w.sessions.OwnedSessionIDs() {
		if w.pairing.Active(sessionID) || transport.HasCredentials(sessionID) {
			continue
		}
		if _, owns := w.sessions.Owns(ctx, sessionID); !owns {
			continue
		}
		state, err := w.store.GetSession(ctx, sessionID)
		if err != nil {
			return err
		}
		if !state.DesiredConnected || state.Status != domain.SessionConnecting {
			continue
		}
		if err := w.pairing.Start(ctx, sessionID); err != nil {
			return err
		}
	}
	return nil
}

func (w *Worker) process(ctx context.Context, command domain.Command) error {
	switch command.Type {
	case domain.CommandProvisionSession:
		var payload struct {
			DesiredConnected bool `json:"desired_connected"`
		}
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return err
		}
		status := domain.SessionDisconnected
		if payload.DesiredConnected {
			status = domain.SessionConnecting
		}
		current, err := w.store.GetSession(ctx, command.SessionID)
		if errors.Is(err, domain.ErrNotFound) {
			current = domain.Session{SessionID: command.SessionID, Status: status}
			err = nil
		}
		if err != nil {
			return err
		}
		current.Status = status
		current.DesiredConnected = payload.DesiredConnected
		current.ReconnectCount = 0
		current.NextReconnectAt = time.Time{}
		return w.store.UpsertSession(ctx, current)

	case domain.CommandPairSession, domain.CommandConnectSession:
		return w.connectSession(ctx, command.SessionID)

	case domain.CommandDisconnectSession:
		current, err := w.ensureSession(ctx, command.SessionID)
		if err != nil {
			return err
		}
		current.DesiredConnected = false
		current.Status = domain.SessionDisconnected
		current.ReconnectCount = 0
		current.NextReconnectAt = time.Time{}
		if err := w.store.UpsertSession(ctx, current); err != nil {
			return err
		}
		w.pairing.Cancel(command.SessionID)
		if err := w.sessions.Disconnect(ctx, command.SessionID); err != nil {
			return err
		}
		return w.appendSessionEvent(ctx, command.SessionID, string(domain.SessionDisconnected), w.now().UTC())

	case domain.CommandResetSession:
		// Compatibility alias: the canonical reconnect is Connect. It selects
		// reconnect or QR from the real credential state.
		return w.connectSession(ctx, command.SessionID)

	case domain.CommandSendMessage:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		var payload domain.MessageSendPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil || payload.To == "" {
			return errors.New("invalid message payload")
		}
		var content []byte
		if payload.Media != nil {
			if w.media == nil || payload.Media.Filename == "" || payload.Media.MIMEType == "" {
				return errors.New("invalid media payload")
			}
			fetched, err := w.media.Fetch(ctx, command.CommandID, payload.Media.SHA256, payload.Media.SizeBytes)
			if err != nil {
				return err
			}
			content = fetched
		}
		if typed, ok := w.transport.(TypedTransport); ok {
			if err := typed.SendTypedMessage(
				ctx, command.SessionID, payload, command.ProviderMessageID, content,
			); err != nil {
				return err
			}
		} else if payload.Media == nil {
			if payload.Text == "" {
				return errors.New("legacy transport only supports non-empty text")
			}
			if err := w.transport.SendText(ctx, command.SessionID, payload.To, payload.Text, command.ProviderMessageID); err != nil {
				return err
			}
		} else {
			caption := payload.Caption
			if caption == "" {
				caption = payload.Text
			}
			if err := w.transport.SendMedia(
				ctx, command.SessionID, payload.To, caption, payload.Media.Filename,
				payload.Media.MIMEType, command.ProviderMessageID, content,
			); err != nil {
				return err
			}
		}
		return w.appendStatusEvent(ctx, command, "SENT", w.now().UTC())

	case domain.CommandEditMessage, domain.CommandRevokeMessage, domain.CommandReactMessage,
		domain.CommandVotePoll, domain.CommandMarkMessage, domain.CommandSetDisappearing,
		domain.CommandRequestUnavailable:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		actions, ok := w.transport.(ActionTransport)
		if !ok {
			return errors.New("transport does not support message actions")
		}
		switch command.Type {
		case domain.CommandEditMessage:
			var payload domain.MessageEditPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.EditMessage(ctx, command.SessionID, payload, command.ProviderMessageID)
		case domain.CommandRevokeMessage:
			var payload domain.MessageTargetPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.RevokeMessage(ctx, command.SessionID, payload, command.ProviderMessageID)
		case domain.CommandReactMessage:
			var payload domain.MessageReactionPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.ReactMessage(ctx, command.SessionID, payload, command.ProviderMessageID)
		case domain.CommandVotePoll:
			var payload domain.PollVotePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.VotePoll(ctx, command.SessionID, payload, command.ProviderMessageID)
		case domain.CommandMarkMessage:
			var payload domain.MessageMarkPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.MarkMessage(ctx, command.SessionID, payload)
		case domain.CommandSetDisappearing:
			var payload domain.DisappearingPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.SetChatDisappearing(ctx, command.SessionID, payload)
		case domain.CommandRequestUnavailable:
			var payload domain.MessageTargetPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return actions.RequestUnavailableMessage(ctx, command.SessionID, payload)
		}
		return errors.New("unsupported message action")

	case domain.CommandSetPresence, domain.CommandSubscribePresence, domain.CommandSetChatPresence:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		presence, ok := w.transport.(PresenceTransport)
		if !ok {
			return errors.New("transport does not support presence")
		}
		switch command.Type {
		case domain.CommandSetPresence:
			var payload domain.PresencePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return presence.SetPresence(ctx, command.SessionID, payload)
		case domain.CommandSubscribePresence:
			var payload domain.ContactPresencePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return presence.SubscribeContactPresence(ctx, command.SessionID, payload)
		case domain.CommandSetChatPresence:
			var payload domain.ChatPresencePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return presence.SetChatPresence(ctx, command.SessionID, payload)
		}
		return errors.New("unsupported presence command")

	case domain.CommandUpdateBlocklist, domain.CommandUpdatePrivacy,
		domain.CommandSetDefaultDisappearing, domain.CommandUpdateChatState:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		policy, ok := w.transport.(AccountPolicyTransport)
		if !ok {
			return errors.New("transport does not support account policy operations")
		}
		switch command.Type {
		case domain.CommandUpdateBlocklist:
			var payload domain.BlocklistUpdatePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return policy.UpdateBlocklist(ctx, command.SessionID, payload)
		case domain.CommandUpdatePrivacy:
			var payload domain.PrivacyUpdatePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return policy.UpdatePrivacy(ctx, command.SessionID, payload)
		case domain.CommandSetDefaultDisappearing:
			var payload domain.DefaultDisappearingPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return policy.SetDefaultDisappearing(ctx, command.SessionID, payload)
		case domain.CommandUpdateChatState:
			var payload domain.ChatStatePayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return policy.UpdateChatState(ctx, command.SessionID, payload)
		}
		return errors.New("unsupported account policy command")

	case domain.CommandRequestHistorySync, domain.CommandRetryMedia:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		recovery, ok := w.transport.(RecoveryTransport)
		if !ok {
			return errors.New("transport does not support history and media recovery")
		}
		switch command.Type {
		case domain.CommandRequestHistorySync:
			var payload domain.HistorySyncPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return recovery.RequestHistorySync(ctx, command.SessionID, payload)
		case domain.CommandRetryMedia:
			var payload domain.MediaRetryPayload
			if err := json.Unmarshal(command.Payload, &payload); err != nil {
				return err
			}
			return recovery.RetryMedia(ctx, command.SessionID, payload)
		}
		return errors.New("unsupported recovery command")

	case domain.CommandLogoutSession:
		current, err := w.ensureSession(ctx, command.SessionID)
		if err != nil {
			return err
		}
		current.DesiredConnected = false
		current.Status = domain.SessionDisconnected
		current.ReconnectCount = 0
		current.NextReconnectAt = time.Time{}
		if err := w.store.UpsertSession(ctx, current); err != nil {
			return err
		}
		w.pairing.Cancel(command.SessionID)
		if err := w.sessions.AcquireLease(ctx, command.SessionID); err != nil {
			return err
		}
		defer w.sessions.Release(ctx, command.SessionID)
		if err := w.transport.Logout(ctx, command.SessionID); err != nil {
			return err
		}
		current, err = w.store.GetSession(ctx, command.SessionID)
		if err != nil {
			return err
		}
		current.DesiredConnected = false
		current.Status = domain.SessionDisconnected
		current.ReconnectCount = 0
		current.NextReconnectAt = time.Time{}
		if err := w.store.UpsertSession(ctx, current); err != nil {
			return err
		}
		return w.appendSessionEvent(ctx, command.SessionID, string(domain.SessionDisconnected), w.now().UTC())
	default:
		return errors.New("unsupported command type")
	}
}

func (w *Worker) connectSession(ctx context.Context, sessionID string) error {
	current, err := w.ensureSession(ctx, sessionID)
	if err != nil {
		return err
	}
	current.DesiredConnected = true
	current.Status = domain.SessionConnecting
	current.NextReconnectAt = time.Time{}
	if err := w.store.UpsertSession(ctx, current); err != nil {
		return err
	}
	if err := w.appendSessionEvent(ctx, sessionID, string(domain.SessionConnecting), w.now().UTC()); err != nil {
		return err
	}
	if err := w.sessions.Reconcile(ctx); err != nil {
		return err
	}
	if _, owns := w.sessions.Owns(ctx, sessionID); !owns {
		return errLeaseUnavailable
	}
	if sessionTransport, ok := w.transport.(SessionTransport); ok && sessionTransport.HasCredentials(sessionID) {
		state, getErr := w.store.GetSession(ctx, sessionID)
		if getErr != nil {
			return getErr
		}
		if state.Status != domain.SessionConnected {
			if err := w.sessions.ConnectOwned(ctx, sessionID); err != nil {
				return err
			}
		}
		return w.appendSessionEvent(ctx, sessionID, string(domain.SessionConnected), w.now().UTC())
	}
	err = w.pairing.Start(ctx, sessionID)
	if errors.Is(err, domain.ErrSessionAlreadyPaired) {
		if connectErr := w.sessions.ConnectOwned(ctx, sessionID); connectErr != nil {
			return connectErr
		}
		return w.appendSessionEvent(ctx, sessionID, string(domain.SessionConnected), w.now().UTC())
	}
	return err
}

func (w *Worker) ensureSession(ctx context.Context, sessionID string) (domain.Session, error) {
	current, err := w.store.GetSession(ctx, sessionID)
	if errors.Is(err, domain.ErrNotFound) {
		return domain.Session{SessionID: sessionID, Status: domain.SessionDisconnected}, nil
	}
	return current, err
}

func (w *Worker) appendStatusEvent(ctx context.Context, command domain.Command, status string, at time.Time) error {
	payload, _ := json.Marshal(map[string]string{
		"provider_message_id": command.ProviderMessageID,
		"status":              status,
	})
	return w.appendEvent(ctx, command.SessionID, domain.EventMessageStatusChanged, payload, at)
}

func (w *Worker) appendSessionEvent(ctx context.Context, sessionID, status string, at time.Time) error {
	payload, _ := json.Marshal(map[string]string{"status": status})
	return w.appendEvent(ctx, sessionID, domain.EventSessionStatusChanged, payload, at)
}

func (w *Worker) appendEvent(
	ctx context.Context,
	sessionID string,
	eventType domain.EventType,
	payload []byte,
	at time.Time,
) error {
	digest := sha256.Sum256(payload)
	event := domain.Event{
		ContractVersion: "v1", EventID: eventID(), SessionID: sessionID,
		Type: eventType, OccurredAt: at, Payload: payload, Digest: hex.EncodeToString(digest[:]),
	}
	_, err := w.store.AppendEvent(ctx, event)
	return err
}

func eventID() string {
	value := make([]byte, 16)
	_, _ = rand.Read(value)
	return "event-" + hex.EncodeToString(value)
}

func retryDelay(attempt int) time.Duration {
	if attempt < 1 {
		attempt = 1
	}
	return min(time.Second*time.Duration(1<<min(attempt-1, 8)), 5*time.Minute)
}

func commandErrorCode(err error) string {
	if errors.Is(err, domain.ErrSessionAlreadyPaired) {
		return "SESSION_ALREADY_PAIRED"
	}
	if errors.Is(err, session.ErrPairingTerminal) {
		return "PAIRING_FAILED"
	}
	if errors.Is(err, errLeaseUnavailable) || errors.Is(err, session.ErrLeaseNotOwned) {
		return "SESSION_LEASE_UNAVAILABLE"
	}
	return "COMMAND_EXECUTION_FAILED"
}
