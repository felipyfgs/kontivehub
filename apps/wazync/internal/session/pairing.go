package session

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type Pairer interface {
	StartPairing(context.Context, string) (<-chan domain.PairingUpdate, error)
}

type advancedPairer interface {
	StartPhonePairing(context.Context, string, string, bool) (<-chan domain.PairingUpdate, error)
	RespondPasskey(context.Context, string, domain.PasskeyResponsePayload) error
	ConfirmPasskey(context.Context, string, bool) error
}

var (
	ErrAdvancedPairingUnsupported = errors.New("advanced pairing is unsupported")
	ErrPairingTerminal            = errors.New("pairing command failed deterministically")
)

const pairingLifetime = 3 * time.Minute

type DeviceRecorder interface {
	RecordDevice(context.Context, string) error
}

type failedDeviceCleaner interface {
	ForgetSession(context.Context, string) error
}

type sessionDisconnector interface {
	Disconnect(string)
}

type PairingCoordinator struct {
	store    store.Store
	pairer   Pairer
	recorder DeviceRecorder
	mu       sync.Mutex
	active   map[string]context.CancelFunc
}

func NewPairingCoordinator(persistence store.Store, pairer Pairer, recorder DeviceRecorder) *PairingCoordinator {
	return &PairingCoordinator{
		store: persistence, pairer: pairer, recorder: recorder,
		active: make(map[string]context.CancelFunc),
	}
}

func (c *PairingCoordinator) Start(ctx context.Context, sessionID string) error {
	pairingCtx, started := c.begin(ctx, sessionID)
	if !started {
		return nil
	}
	updates, err := c.pairer.StartPairing(pairingCtx, sessionID)
	if err != nil {
		c.finish(sessionID)
		c.recordStartFailure(ctx, sessionID, err)
		return errors.Join(ErrPairingTerminal, err)
	}
	if err := c.store.SetSessionStatus(ctx, sessionID, domain.SessionConnecting, 0, time.Time{}); err != nil {
		c.finish(sessionID)
		return err
	}
	go c.consume(pairingCtx, sessionID, updates)
	return nil
}

func (c *PairingCoordinator) StartPhone(
	ctx context.Context,
	sessionID, phone string,
	showPushNotification bool,
) error {
	pairingCtx, started := c.begin(ctx, sessionID)
	if !started {
		return nil
	}
	pairer, ok := c.pairer.(advancedPairer)
	if !ok {
		c.finish(sessionID)
		return ErrAdvancedPairingUnsupported
	}
	updates, err := pairer.StartPhonePairing(pairingCtx, sessionID, phone, showPushNotification)
	if err != nil {
		c.finish(sessionID)
		c.recordStartFailure(ctx, sessionID, err)
		return errors.Join(ErrPairingTerminal, err)
	}
	if err := c.store.SetSessionStatus(ctx, sessionID, domain.SessionConnecting, 0, time.Time{}); err != nil {
		c.finish(sessionID)
		return err
	}
	go c.consume(pairingCtx, sessionID, updates)
	return nil
}

func (c *PairingCoordinator) RespondPasskey(
	ctx context.Context,
	sessionID string,
	payload domain.PasskeyResponsePayload,
) error {
	pairer, ok := c.pairer.(advancedPairer)
	if !ok {
		return ErrAdvancedPairingUnsupported
	}
	return pairer.RespondPasskey(ctx, sessionID, payload)
}

func (c *PairingCoordinator) ConfirmPasskey(ctx context.Context, sessionID string, confirm bool) error {
	pairer, ok := c.pairer.(advancedPairer)
	if !ok {
		return ErrAdvancedPairingUnsupported
	}
	return pairer.ConfirmPasskey(ctx, sessionID, confirm)
}

func (c *PairingCoordinator) consume(ctx context.Context, sessionID string, updates <-chan domain.PairingUpdate) {
	defer c.finish(sessionID)
	terminal := false
	for update := range updates {
		switch update.Event {
		case "success":
			if c.recorder != nil {
				if err := c.recorder.RecordDevice(ctx, sessionID); err != nil {
					slog.Error("paired device mapping failed", "session_id", sessionID, "error_class", "DEVICE_MAPPING_FAILED")
					if cleaner, ok := c.recorder.(failedDeviceCleaner); ok {
						_ = cleaner.ForgetSession(ctx, sessionID)
					}
					c.appendUpdate(ctx, sessionID, domain.PairingUpdate{
						Event: "error", ErrorCode: "DEVICE_MAPPING_FAILED",
					})
					c.setDisconnected(ctx, sessionID)
					return
				}
			}
			c.appendUpdate(ctx, sessionID, update)
			_ = c.store.SetSessionStatus(ctx, sessionID, domain.SessionConnected, 0, time.Time{})
			c.appendStatus(ctx, sessionID, domain.SessionConnected)
			terminal = true
		case "timeout", "error", "err-unexpected-state", "err-client-outdated", "err-scanned-without-multidevice":
			c.appendUpdate(ctx, sessionID, update)
			c.setDisconnected(ctx, sessionID)
			terminal = true
		default:
			c.appendUpdate(ctx, sessionID, update)
		}
	}
	if terminal {
		return
	}
	state, err := c.store.GetSession(context.Background(), sessionID)
	if err != nil || !state.DesiredConnected {
		return
	}
	update := domain.PairingUpdate{Event: "error", ErrorCode: "PAIRING_CHANNEL_CLOSED"}
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		update = domain.PairingUpdate{Event: "timeout", ErrorCode: "CONNECT_TIMEOUT"}
	}
	c.appendUpdate(context.Background(), sessionID, update)
	c.setDisconnected(context.Background(), sessionID)
}

func (c *PairingCoordinator) recordStartFailure(ctx context.Context, sessionID string, err error) {
	errorCode := "PAIRING_FAILED"
	if errors.Is(err, domain.ErrSessionAlreadyPaired) {
		errorCode = "SESSION_ALREADY_PAIRED"
	}
	c.appendUpdate(ctx, sessionID, domain.PairingUpdate{Event: "error", ErrorCode: errorCode})
	c.setDisconnected(ctx, sessionID)
}

func (c *PairingCoordinator) Cancel(sessionID string) {
	c.finish(sessionID)
}

func (c *PairingCoordinator) Active(sessionID string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	_, active := c.active[sessionID]
	return active
}

func (c *PairingCoordinator) begin(parent context.Context, sessionID string) (context.Context, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if _, exists := c.active[sessionID]; exists {
		return nil, false
	}
	ctx, cancel := context.WithTimeout(parent, pairingLifetime)
	c.active[sessionID] = cancel
	return ctx, true
}

func (c *PairingCoordinator) finish(sessionID string) {
	c.mu.Lock()
	cancel := c.active[sessionID]
	delete(c.active, sessionID)
	c.mu.Unlock()
	if cancel != nil {
		cancel()
	}
}

func (c *PairingCoordinator) setDisconnected(ctx context.Context, sessionID string) {
	sessionState, err := c.store.GetSession(ctx, sessionID)
	if err != nil {
		return
	}
	sessionState.Status = domain.SessionDisconnected
	sessionState.DesiredConnected = false
	sessionState.ReconnectCount = 0
	sessionState.NextReconnectAt = time.Time{}
	sessionState.UpdatedAt = time.Now().UTC()
	_ = c.store.UpsertSession(ctx, sessionState)
	if disconnector, ok := c.pairer.(sessionDisconnector); ok {
		disconnector.Disconnect(sessionID)
	}
	c.appendStatus(ctx, sessionID, domain.SessionDisconnected)
}

func (c *PairingCoordinator) appendStatus(ctx context.Context, sessionID string, status domain.SessionStatus) {
	payload, _ := json.Marshal(map[string]string{"status": string(status)})
	digest := sha256.Sum256(payload)
	if _, err := c.store.AppendEvent(ctx, domain.Event{
		ContractVersion: "v1", EventID: randomID("session"), SessionID: sessionID,
		Type: domain.EventSessionStatusChanged, OccurredAt: time.Now().UTC(), Payload: payload,
		Digest: hex.EncodeToString(digest[:]),
	}); err != nil {
		slog.Error("wazync.pairing.append_status_failed",
			"session_id", sessionID,
			"status", string(status),
			"error_class", "persist",
		)
	}
}

func (c *PairingCoordinator) appendUpdate(ctx context.Context, sessionID string, update domain.PairingUpdate) {
	payloadData := map[string]any{"event": update.Event}
	if update.Code != "" {
		payloadData["code"] = update.Code
	}
	if !update.ExpiresAt.IsZero() {
		payloadData["expires_at"] = update.ExpiresAt
	}
	if update.ErrorCode != "" {
		payloadData["error_code"] = update.ErrorCode
	}
	if update.PasskeyRequest != nil {
		payloadData["passkey_request"] = update.PasskeyRequest
	}
	payload, _ := json.Marshal(payloadData)
	digest := sha256.Sum256(payload)
	event := domain.Event{
		ContractVersion: "v1", EventID: randomID("pairing"), SessionID: sessionID,
		Type: domain.EventPairingUpdated, OccurredAt: time.Now().UTC(), Payload: payload,
		Digest: hex.EncodeToString(digest[:]),
	}
	if _, err := c.store.AppendEvent(ctx, event); err != nil {
		slog.Error("wazync.pairing.append_update_failed",
			"session_id", sessionID,
			"event", update.Event,
			"error_class", "persist",
		)
	}
}

func randomID(prefix string) string {
	random := make([]byte, 16)
	_, _ = rand.Read(random)
	return prefix + "-" + hex.EncodeToString(random)
}
