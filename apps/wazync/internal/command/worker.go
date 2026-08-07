package command

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"strconv"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

var (
	errLeaseUnavailable         = errors.New("session lease unavailable")
	errOutboundMediaUnavailable = errors.New("outbound media unavailable")
)

type Transport interface {
	SendTypedMessage(context.Context, string, domain.MessageSendPayload, string, []byte) error
	Logout(context.Context, string) error
}

type SessionTransport interface {
	HasCredentials(string) bool
	ForgetSession(context.Context, string) error
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

type StickerMaterializationTransport interface {
	MaterializeSticker(context.Context, string, domain.StickerMaterializationPayload) (protocol.StickerMaterializationResult, error)
}

type MediaSource interface {
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
	mediaSource MediaSource
	now         func() time.Time
}

func (w *Worker) WithMediaSource(source MediaSource) *Worker {
	w.mediaSource = source
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
		if pending.Command.Type == domain.CommandMaterializeSticker {
			if err := w.processStickerMaterialization(ctx, pending); err != nil {
				return err
			}
			continue
		}
		err := w.process(ctx, pending.Command, pending.Attempts)
		if errors.Is(err, protocol.ErrMediaRetryClaimLost) {
			// The receipt generation was claimed by another execution. Acknowledge
			// this duplicate only if this worker still owns the command attempt;
			// otherwise the newer owner decides its terminal state.
			if finalizeErr := w.store.FinalizeMediaRetryCommandProcessed(
				ctx, pending.Command.CommandID, pending.Attempts, now,
			); finalizeErr != nil && !errors.Is(finalizeErr, domain.ErrStateConflict) {
				return finalizeErr
			}
			continue
		}
		if err == nil {
			if pending.Command.Type == domain.CommandRetryMedia {
				err = w.store.FinalizeMediaRetryCommandProcessed(ctx, pending.Command.CommandID, pending.Attempts, now)
			} else if pending.Command.Type == domain.CommandSendMessageBatch {
				err = w.store.FinalizeMessageBatchCommandProcessed(
					ctx, pending.Command.CommandID, pending.Attempts, now,
				)
			} else if isTerminalMessageAction(pending.Command.Type) {
				event, eventErr := w.messageActionResultEvent(pending.Command, "SUCCEEDED", "", now)
				if eventErr != nil {
					return eventErr
				}
				err = w.store.FinalizeCommandProcessedWithEvent(
					ctx, pending.Command.CommandID, pending.Attempts, now, event,
				)
			} else {
				err = w.store.MarkCommandProcessed(ctx, pending.Command.CommandID, now)
			}
			if err != nil && !((pending.Command.Type == domain.CommandRetryMedia ||
				pending.Command.Type == domain.CommandSendMessageBatch ||
				isTerminalMessageAction(pending.Command.Type)) && errors.Is(err, domain.ErrStateConflict)) {
				return err
			}
			continue
		}
		terminal := errors.Is(err, session.ErrPairingTerminal)
		if pending.Command.Type == domain.CommandRetryMedia {
			terminal = terminal || errors.Is(err, protocol.ErrMediaRetryStateMissing) ||
				errors.Is(err, protocol.ErrHistoryRecoveryInvalid)
		}
		terminalByAttempts := pending.Attempts >= w.maxAttempts
		terminal = terminal || terminalByAttempts
		if terminal && pending.Command.Type == domain.CommandSendMessage {
			status, errorCode := "UNKNOWN", ""
			if errors.Is(err, errOutboundMediaUnavailable) {
				status, errorCode = "FAILED", "OUTBOUND_MEDIA_UNAVAILABLE"
				event := w.messageStatusEvent(
					pending.Command, status, errorCode, now,
					"message-status-terminal-"+stableCommandEventID(pending.Command.CommandID, status, errorCode),
				)
				if finalizeErr := w.store.FinalizeCommandFailureWithEvent(
					ctx, pending.Command.CommandID, pending.Attempts, now, errorCode, event,
				); finalizeErr != nil && !errors.Is(finalizeErr, domain.ErrStateConflict) {
					return finalizeErr
				}
				continue
			}
			if eventErr := w.appendStatusEvent(ctx, pending.Command, status, errorCode, now); eventErr != nil {
				return eventErr
			}
		}
		if terminal && pending.Command.Type == domain.CommandRetryMedia {
			code := mediaRetryTerminalCode(err)
			event, eventErr := w.mediaRetryFailureEvent(ctx, pending.Command, code, now, mediaRetryClaim(err))
			if eventErr != nil {
				return eventErr
			}
			if finalizeErr := w.store.FinalizeCommandFailureWithEvent(
				ctx, pending.Command.CommandID, pending.Attempts, now, commandErrorCode(err), event,
			); finalizeErr != nil && !errors.Is(finalizeErr, domain.ErrStateConflict) {
				return finalizeErr
			}
			slog.Warn("command execution failed",
				"command_id", pending.Command.CommandID,
				"type", pending.Command.Type,
				"session_id", pending.Command.SessionID,
				"attempt", pending.Attempts,
				"error_class", commandErrorCode(err),
			)
			continue
		}
		if terminal && pending.Command.Type == domain.CommandSendMessageBatch {
			if finalizeErr := w.finalizeRemainingBatchItems(
				ctx, pending.Command, pending.Attempts, domain.MessageBatchItemUnknown,
				"BATCH_SEND_OUTCOME_UNKNOWN", now,
			); finalizeErr != nil && !errors.Is(finalizeErr, domain.ErrStateConflict) {
				return finalizeErr
			}
		}
		if terminal && isTerminalMessageAction(pending.Command.Type) {
			actionErrorCode := terminalMessageActionErrorCode(err, terminalByAttempts)
			event, eventErr := w.messageActionResultEvent(pending.Command, "FAILED", actionErrorCode, now)
			if eventErr != nil {
				return eventErr
			}
			if finalizeErr := w.store.FinalizeCommandFailureWithEvent(
				ctx, pending.Command.CommandID, pending.Attempts, now, actionErrorCode, event,
			); finalizeErr != nil && !errors.Is(finalizeErr, domain.ErrStateConflict) {
				return finalizeErr
			}
			continue
		}
		slog.Warn("command execution failed",
			"command_id", pending.Command.CommandID,
			"type", pending.Command.Type,
			"session_id", pending.Command.SessionID,
			"attempt", pending.Attempts,
			"error_class", commandErrorCode(err),
		)
		if pending.Command.Type == domain.CommandRetryMedia {
			err = w.store.FinalizeMediaRetryCommandFailed(
				ctx, pending.Command.CommandID, pending.Attempts,
				now.Add(retryDelay(pending.Attempts)), commandErrorCode(err),
			)
		} else if pending.Command.Type == domain.CommandSendMessageBatch {
			err = w.store.FinalizeMessageBatchCommandFailed(
				ctx, pending.Command.CommandID, pending.Attempts,
				now.Add(retryDelay(pending.Attempts)), commandErrorCode(err), terminal,
			)
		} else {
			err = w.store.MarkCommandFailed(
				ctx, pending.Command.CommandID, now.Add(retryDelay(pending.Attempts)),
				commandErrorCode(err), terminal,
			)
		}
		if err != nil && !((pending.Command.Type == domain.CommandRetryMedia ||
			pending.Command.Type == domain.CommandSendMessageBatch) && errors.Is(err, domain.ErrStateConflict)) {
			return err
		}
	}
	return w.reconcilePairingSessions(ctx)
}

func (w *Worker) processStickerMaterialization(ctx context.Context, pending domain.PendingCommand) error {
	command := pending.Command
	if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
		return w.store.MarkCommandFailed(
			ctx, command.CommandID, w.now().UTC().Add(retryDelay(pending.Attempts)),
			"SESSION_LEASE_UNAVAILABLE", false,
		)
	}
	materializer, ok := w.transport.(StickerMaterializationTransport)
	if !ok {
		return w.finalizeStickerMaterializationFailure(
			ctx, command, pending.Attempts, "UNSUPPORTED", w.now().UTC(),
		)
	}
	var payload domain.StickerMaterializationPayload
	if err := json.Unmarshal(command.Payload, &payload); err != nil {
		return w.finalizeStickerMaterializationFailure(
			ctx, command, pending.Attempts, "INVALID_REQUEST", w.now().UTC(),
		)
	}
	result, err := materializer.MaterializeSticker(ctx, command.SessionID, payload)
	if err == nil {
		event := stickerMaterializationEvent(command, payload, result, "READY", "", pending.Attempts, w.now().UTC())
		err = w.store.FinalizeCommandProcessedWithEvent(
			ctx, command.CommandID, pending.Attempts, w.now().UTC(), event,
		)
		if errors.Is(err, domain.ErrStateConflict) {
			return nil
		}
		return err
	}

	reason, retryable := "DOWNLOAD_FAILED", true
	var materializationError *protocol.StickerMaterializationError
	if errors.As(err, &materializationError) {
		reason, retryable = materializationError.Reason, materializationError.Retryable
	}
	if retryable && pending.Attempts < w.maxAttempts {
		return w.store.MarkCommandFailed(
			ctx, command.CommandID, w.now().UTC().Add(retryDelay(pending.Attempts)), reason, false,
		)
	}
	return w.finalizeStickerMaterializationFailure(
		ctx, command, pending.Attempts, reason, w.now().UTC(),
	)
}

func (w *Worker) finalizeStickerMaterializationFailure(
	ctx context.Context,
	command domain.Command,
	attempt int,
	reason string,
	at time.Time,
) error {
	var payload domain.StickerMaterializationPayload
	if err := json.Unmarshal(command.Payload, &payload); err != nil {
		payload.ObservationID = "invalid-request"
	}
	event := stickerMaterializationEvent(
		command, payload, protocol.StickerMaterializationResult{}, "FAILED", reason, attempt, at,
	)
	err := w.store.FinalizeCommandFailureWithEvent(
		ctx, command.CommandID, attempt, at, reason, event,
	)
	if errors.Is(err, domain.ErrStateConflict) {
		return nil
	}
	return err
}

func stickerMaterializationEvent(
	command domain.Command,
	request domain.StickerMaterializationPayload,
	result protocol.StickerMaterializationResult,
	status string,
	errorCode string,
	attempt int,
	at time.Time,
) domain.Event {
	payload := map[string]any{
		"command_id": command.CommandID, "observation_id": request.ObservationID,
		"status": status, "attempt": attempt,
	}
	if errorCode != "" {
		payload["error_code"] = errorCode
	}
	if status == "READY" {
		payload["spool_id"] = result.SpoolID
		payload["size_bytes"] = result.SizeBytes
		payload["sha256"] = result.SHA256
		payload["mime_type"] = result.MIMEType
	}
	encoded, _ := json.Marshal(payload)
	digest := sha256.Sum256(encoded)
	return domain.Event{
		ContractVersion: "v1",
		EventID:         "sticker-materialization-" + stableCommandEventID(command.CommandID),
		SessionID:       command.SessionID,
		Type:            domain.EventStickerMaterialized,
		OccurredAt:      at,
		Payload:         encoded,
		Digest:          hex.EncodeToString(digest[:]),
	}
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

func (w *Worker) process(ctx context.Context, command domain.Command, attempt int) error {
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

	case domain.CommandSendMessage:
		if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
			return errLeaseUnavailable
		}
		var payload domain.MessageSendPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil || payload.To == "" {
			return errors.New("invalid message payload")
		}
		if payload.Media != nil && payload.Media.PTV {
			return errors.New("PTV builder is unavailable")
		}
		var content []byte
		if payload.Media != nil {
			if w.mediaSource == nil || payload.Media.Filename == "" || payload.Media.MIMEType == "" {
				return errors.New("invalid media payload")
			}
			fetched, err := w.mediaSource.Fetch(ctx, command.CommandID, payload.Media.SHA256, payload.Media.SizeBytes)
			if err != nil {
				return fmt.Errorf("%w: %v", errOutboundMediaUnavailable, err)
			}
			content = fetched
		}
		if err := w.transport.SendTypedMessage(
			ctx, command.SessionID, payload, command.ProviderMessageID, content,
		); err != nil {
			return err
		}
		return w.appendStatusEvent(ctx, command, "SENT", "", w.now().UTC())

	case domain.CommandSendMessageBatch:
		return w.processMessageBatch(ctx, command, attempt)

	case domain.CommandEditMessage, domain.CommandRevokeMessage, domain.CommandReactMessage,
		domain.CommandVotePoll, domain.CommandMarkMessage, domain.CommandSetDisappearing,
		domain.CommandRequestUnavailableMessage:
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
		case domain.CommandRequestUnavailableMessage:
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

func (w *Worker) processMessageBatch(
	ctx context.Context,
	command domain.Command,
	attempt int,
) error {
	if _, owns := w.sessions.Owns(ctx, command.SessionID); !owns {
		return errLeaseUnavailable
	}
	var payload domain.MessageBatchPayload
	if err := json.Unmarshal(command.Payload, &payload); err != nil {
		return errors.New("invalid message batch payload")
	}
	if err := payload.Validate(); err != nil {
		return err
	}

	for _, item := range payload.OrderedItems() {
		state, err := w.store.GetMessageBatchItemState(ctx, command.CommandID, item.Position)
		if err != nil {
			return err
		}
		if state.BatchID != item.BatchID || state.Size != item.Size ||
			state.ProviderMessageID != item.ProviderMessageID {
			return domain.ErrStateConflict
		}
		if state.Status.Terminal() {
			continue
		}

		message := item.Message
		if message.Media == nil || w.mediaSource == nil || message.Media.Filename == "" || message.Media.MIMEType == "" {
			return errors.New("invalid batch media payload")
		}
		if message.Media.PTV {
			return errors.New("PTV builder is unavailable")
		}
		content, err := w.mediaSource.Fetch(
			ctx, item.ProviderMessageID, message.Media.SHA256, message.Media.SizeBytes,
		)
		if err != nil {
			if attempt < w.maxAttempts {
				return fmt.Errorf("%w: %v", errOutboundMediaUnavailable, err)
			}
			if err := w.finalizeBatchItem(
				ctx, command, attempt, item, domain.MessageBatchItemFailed,
				"OUTBOUND_MEDIA_UNAVAILABLE", w.now().UTC(),
			); err != nil {
				return err
			}
			continue
		}
		if err := w.transport.SendTypedMessage(
			ctx, command.SessionID, message, item.ProviderMessageID, content,
		); err != nil {
			if attempt < w.maxAttempts {
				return err
			}
			if err := w.finalizeBatchItem(
				ctx, command, attempt, item, domain.MessageBatchItemUnknown,
				"BATCH_SEND_OUTCOME_UNKNOWN", w.now().UTC(),
			); err != nil {
				return err
			}
			continue
		}
		if err := w.finalizeBatchItem(
			ctx, command, attempt, item, domain.MessageBatchItemSent, "", w.now().UTC(),
		); err != nil {
			return err
		}
	}
	return nil
}

func (w *Worker) finalizeRemainingBatchItems(
	ctx context.Context,
	command domain.Command,
	attempt int,
	status domain.MessageBatchItemStatus,
	errorCode string,
	at time.Time,
) error {
	var payload domain.MessageBatchPayload
	if err := json.Unmarshal(command.Payload, &payload); err != nil {
		return err
	}
	for _, item := range payload.OrderedItems() {
		state, err := w.store.GetMessageBatchItemState(ctx, command.CommandID, item.Position)
		if err != nil {
			return err
		}
		if state.Status.Terminal() {
			continue
		}
		if err := w.finalizeBatchItem(ctx, command, attempt, item, status, errorCode, at); err != nil {
			return err
		}
	}
	return nil
}

func (w *Worker) finalizeBatchItem(
	ctx context.Context,
	command domain.Command,
	attempt int,
	item domain.MessageBatchItemPayload,
	status domain.MessageBatchItemStatus,
	errorCode string,
	at time.Time,
) error {
	event := batchItemStatusEvent(command, item, status, errorCode, at)
	return w.store.FinalizeMessageBatchItemWithEvent(
		ctx, command.CommandID, attempt, item, status, errorCode, at, event,
	)
}

func batchItemStatusEvent(
	command domain.Command,
	item domain.MessageBatchItemPayload,
	status domain.MessageBatchItemStatus,
	errorCode string,
	at time.Time,
) domain.Event {
	payloadValues := map[string]string{
		"provider_message_id": item.ProviderMessageID,
		"status":              string(status),
	}
	if errorCode != "" {
		payloadValues["error_code"] = errorCode
	}
	payload, _ := json.Marshal(payloadValues)
	digest := sha256.Sum256(payload)
	return domain.Event{
		ContractVersion: "v1",
		EventID: "batch-item-" + stableCommandEventID(
			command.CommandID, item.BatchID, strconv.Itoa(item.Position), string(status), errorCode,
		),
		SessionID: command.SessionID,
		Type:      domain.EventMessageStatusChanged, OccurredAt: at,
		Payload: payload, Digest: hex.EncodeToString(digest[:]),
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

func (w *Worker) appendStatusEvent(
	ctx context.Context,
	command domain.Command,
	status, errorCode string,
	at time.Time,
) error {
	event := w.messageStatusEvent(command, status, errorCode, at, eventID())
	_, err := w.store.AppendEvent(ctx, event)
	return err
}

func (w *Worker) messageStatusEvent(
	command domain.Command,
	status, errorCode string,
	at time.Time,
	id string,
) domain.Event {
	payloadValues := map[string]string{
		"provider_message_id": command.ProviderMessageID,
		"status":              status,
	}
	if errorCode != "" {
		payloadValues["error_code"] = errorCode
	}
	payload, _ := json.Marshal(payloadValues)
	digest := sha256.Sum256(payload)
	return domain.Event{
		ContractVersion: "v1", EventID: id, SessionID: command.SessionID,
		Type: domain.EventMessageStatusChanged, OccurredAt: at,
		Payload: payload, Digest: hex.EncodeToString(digest[:]),
	}
}

func isTerminalMessageAction(commandType domain.CommandType) bool {
	switch commandType {
	case domain.CommandEditMessage, domain.CommandRevokeMessage, domain.CommandReactMessage:
		return true
	default:
		return false
	}
}

func terminalMessageActionErrorCode(err error, exhaustedAttempts bool) string {
	if exhaustedAttempts {
		return "ACTION_RETRY_EXHAUSTED"
	}
	if errors.Is(err, session.ErrPairingTerminal) {
		return "ACTION_REJECTED"
	}
	return "ACTION_OUTCOME_UNKNOWN"
}

func (w *Worker) messageActionResultEvent(
	command domain.Command,
	status string,
	errorCode string,
	at time.Time,
) (domain.Event, error) {
	var targetMessageID, action string
	switch command.Type {
	case domain.CommandEditMessage:
		var payload domain.MessageEditPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return domain.Event{}, err
		}
		targetMessageID, action = payload.TargetMessageID, "EDIT"
	case domain.CommandRevokeMessage:
		var payload domain.MessageTargetPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return domain.Event{}, err
		}
		targetMessageID, action = payload.TargetMessageID, "REVOKE"
	case domain.CommandReactMessage:
		var payload domain.MessageReactionPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return domain.Event{}, err
		}
		targetMessageID, action = payload.TargetMessageID, "REACTION"
	default:
		return domain.Event{}, errors.New("unsupported terminal message action")
	}
	payload := map[string]string{
		"command_id":          command.CommandID,
		"action":              action,
		"status":              status,
		"provider_message_id": command.ProviderMessageID,
		"target_message_id":   targetMessageID,
	}
	if errorCode != "" {
		payload["error_code"] = errorCode
	}
	encoded, err := json.Marshal(payload)
	if err != nil {
		return domain.Event{}, err
	}
	digest := sha256.Sum256(encoded)
	return domain.Event{
		ContractVersion: "v1",
		EventID:         "message-action-result-" + stableCommandEventID(command.CommandID),
		SessionID:       command.SessionID,
		Type:            domain.EventMessageActionResult,
		OccurredAt:      at,
		Payload:         encoded,
		Digest:          hex.EncodeToString(digest[:]),
	}, nil
}

func (w *Worker) appendSessionEvent(ctx context.Context, sessionID, status string, at time.Time) error {
	payload, _ := json.Marshal(map[string]string{"status": status})
	return w.appendEvent(ctx, sessionID, domain.EventSessionStatusChanged, payload, at)
}

func (w *Worker) appendMediaRetryFailure(ctx context.Context, command domain.Command, code string, at time.Time) error {
	event, err := w.mediaRetryFailureEvent(ctx, command, code, at, nil)
	if err != nil {
		return err
	}
	_, err = w.store.AppendEvent(ctx, event)
	return err
}

func (w *Worker) mediaRetryFailureEvent(
	ctx context.Context,
	command domain.Command,
	code string,
	at time.Time,
	claim *protocol.MediaRetryClaim,
) (domain.Event, error) {
	var payload domain.MediaRetryPayload
	if err := json.Unmarshal(command.Payload, &payload); err != nil {
		return domain.Event{}, err
	}
	generation, attempt := 0, 0
	if claim != nil {
		generation, attempt = claim.Generation, claim.Attempt
	} else {
		state, err := w.store.GetMediaRetryState(ctx, command.SessionID, payload.TargetMessageID)
		if err != nil && !errors.Is(err, domain.ErrNotFound) {
			return domain.Event{}, err
		}
		// A missing descriptor is itself a terminal, fail-closed condition. In
		// that case zero is authoritative because no generation was claimed.
		generation, attempt = state.Generation, state.Attempts
	}
	encoded, err := json.Marshal(map[string]any{
		"provider_message_id": payload.TargetMessageID,
		"status":              "FAILED",
		"error_code":          code,
		"generation":          generation,
		"attempt":             attempt,
	})
	if err != nil {
		return domain.Event{}, err
	}
	digest := sha256.Sum256(encoded)
	return domain.Event{
		ContractVersion: "v1",
		EventID:         "media-retry-terminal-" + stableCommandEventID(command.CommandID, payload.TargetMessageID, code, strconv.Itoa(generation), strconv.Itoa(attempt)),
		SessionID:       command.SessionID,
		Type:            domain.EventMediaRetryUpdated,
		OccurredAt:      at,
		Payload:         encoded,
		Digest:          hex.EncodeToString(digest[:]),
	}, nil
}

func mediaRetryClaim(err error) *protocol.MediaRetryClaim {
	var receiptErr *protocol.MediaRetryReceiptError
	if !errors.As(err, &receiptErr) {
		return nil
	}
	claim := receiptErr.Claim
	return &claim
}

func mediaRetryTerminalCode(err error) string {
	// Keep the receiver-facing vocabulary bounded and independent of provider
	// errors. This is also the only code emitted by terminal command handling.
	if errors.Is(err, protocol.ErrMediaRetryStateMissing) {
		return "MEDIA_RETRY_STATE_MISSING"
	}
	if errors.Is(err, protocol.ErrHistoryRecoveryInvalid) {
		return "MEDIA_RETRY_INVALID_REQUEST"
	}
	return "MEDIA_RETRY_REQUEST_FAILED"
}

func (w *Worker) appendEvent(
	ctx context.Context,
	sessionID string,
	eventType domain.EventType,
	payload []byte,
	at time.Time,
) error {
	return w.appendEventWithID(ctx, eventID(), sessionID, eventType, payload, at)
}

func (w *Worker) appendEventWithID(
	ctx context.Context,
	id, sessionID string,
	eventType domain.EventType,
	payload []byte,
	at time.Time,
) error {
	digest := sha256.Sum256(payload)
	event := domain.Event{
		ContractVersion: "v1", EventID: id, SessionID: sessionID,
		Type: eventType, OccurredAt: at, Payload: payload, Digest: hex.EncodeToString(digest[:]),
	}
	_, err := w.store.AppendEvent(ctx, event)
	return err
}

func stableCommandEventID(parts ...string) string {
	hash := sha256.New()
	for _, part := range parts {
		_, _ = hash.Write([]byte(part))
		_, _ = hash.Write([]byte{0})
	}
	return hex.EncodeToString(hash.Sum(nil))[:24]
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
