package protocol

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"sync"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
)

const (
	hasWhatsMeowDeviceSQL    = `SELECT EXISTS (SELECT 1 FROM wazync.whatsmeow_device WHERE jid = $1)`
	deleteWhatsMeowDeviceSQL = `DELETE FROM wazync.whatsmeow_device WHERE jid = $1`
)

type DeviceResolver struct {
	mu           sync.Mutex
	db           *sql.DB
	container    *sqlstore.Container
	box          *cryptobox.Box
	clients      map[string]*whatsmeow.Client
	handlers     map[string]uint32
	provisioning map[string]bool
	settings     ClientSettings
	ctx          context.Context
	eventSink    func(string, *whatsmeow.Client, any) bool
}

func OpenDeviceResolver(
	ctx context.Context,
	databaseURL string,
	box *cryptobox.Box,
	clientSettings ...ClientSettings,
) (*DeviceResolver, error) {
	settings := ClientSettings{}.normalized()
	if len(clientSettings) > 0 {
		settings = clientSettings[0].normalized()
	}
	if err := settings.validate(); err != nil {
		return nil, err
	}
	databaseConfig, err := wazyncDatabaseConfig(databaseURL)
	if err != nil {
		return nil, fmt.Errorf("open WhatsMeow device database: %w", err)
	}
	db := stdlib.OpenDB(*databaseConfig)
	container := sqlstore.NewWithDB(db, "postgres", nil)
	if err := container.Upgrade(ctx); err != nil {
		_ = db.Close()
		return nil, fmt.Errorf("upgrade WhatsMeow device database: %w", err)
	}
	return &DeviceResolver{
		db: db, container: container, box: box, clients: make(map[string]*whatsmeow.Client),
		handlers: make(map[string]uint32), provisioning: make(map[string]bool), settings: settings, ctx: ctx,
	}, nil
}

func wazyncDatabaseConfig(databaseURL string) (*pgx.ConnConfig, error) {
	databaseConfig, err := pgx.ParseConfig(databaseURL)
	if err != nil {
		return nil, err
	}
	databaseConfig.RuntimeParams["search_path"] = "wazync"
	return databaseConfig, nil
}

// Peek returns a cached client without creating a provisional NewDevice.
// Status polls and Disconnect must use Peek so unpaired sessions stay inert.
func (r *DeviceResolver) Peek(sessionID string) (WhatsMeowClient, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	client, ok := r.clients[sessionID]
	if !ok || client == nil {
		return nil, false
	}
	return client, true
}

func (r *DeviceResolver) Resolve(sessionID string) (WhatsMeowClient, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if client, ok := r.clients[sessionID]; ok {
		return client, nil
	}

	var ciphertext, nonce []byte
	err := r.db.QueryRowContext(r.ctx, `
SELECT device_jid_cipher, device_jid_nonce
FROM wazync.session_devices
WHERE session_id = $1`, sessionID).Scan(&ciphertext, &nonce)

	// Align with WuzAPI: NewDevice only when the session has no stored jid.
	var device *store.Device
	switch {
	case errors.Is(err, sql.ErrNoRows):
		device = r.container.NewDevice()
	case err != nil:
		return nil, fmt.Errorf("read session device mapping: %w", err)
	default:
		plain, decryptErr := r.box.Open(ciphertext, nonce, []byte(sessionID))
		if decryptErr != nil {
			return nil, fmt.Errorf("decrypt session device mapping: %w", decryptErr)
		}
		jid, parseErr := types.ParseJID(string(plain))
		if parseErr != nil {
			return nil, fmt.Errorf("parse stored device JID: %w", parseErr)
		}
		device, err = r.container.GetDevice(r.ctx, jid)
		if err != nil {
			return nil, fmt.Errorf("load WhatsMeow device: %w", err)
		}
		if device == nil {
			if _, cleanupErr := r.db.ExecContext(r.ctx, `
DELETE FROM wazync.session_devices WHERE session_id = $1`, sessionID); cleanupErr != nil {
				return nil, fmt.Errorf("remove orphaned session device mapping: %w", cleanupErr)
			}
			device = r.container.NewDevice()
		}
	}

	client := whatsmeow.NewClient(device, nil)
	if err := configureWhatsMeowClient(client, r.ctx, r.settings); err != nil {
		return nil, err
	}
	if r.eventSink != nil {
		sink := r.eventSink
		r.handlers[sessionID] = client.AddEventHandlerWithSuccessStatus(func(event any) bool {
			return sink(sessionID, client, event)
		})
	}
	r.clients[sessionID] = client
	if client.Store.ID == nil {
		if r.provisioning == nil {
			r.provisioning = make(map[string]bool)
		}
		r.provisioning[sessionID] = true
	} else {
		delete(r.provisioning, sessionID)
	}
	return client, nil
}

// HasDevice validates both the encrypted mapping and the real WhatsMeow row.
// Orphan mappings are removed and no identifier leaves this boundary.
func (r *DeviceResolver) HasDevice(sessionID string) bool {
	r.mu.Lock()

	var ciphertext, nonce []byte
	err := r.db.QueryRowContext(r.ctx, `
SELECT device_jid_cipher, device_jid_nonce
FROM wazync.session_devices
	WHERE session_id = $1`, sessionID).Scan(&ciphertext, &nonce)
	if errors.Is(err, sql.ErrNoRows) {
		// NewDevice has no JID until PairSuccess. It is an active provisional
		// identity, not an orphan, and disconnecting it invalidates the QR.
		if r.isProvisioningLocked(sessionID) {
			r.mu.Unlock()
			return false
		}
		client, handlerID, hasHandler := r.detachClientLocked(sessionID)
		r.mu.Unlock()
		disconnectDetachedClient(client, handlerID, hasHandler)
		return false
	}
	if err != nil {
		r.mu.Unlock()
		return false
	}
	plain, err := r.box.Open(ciphertext, nonce, []byte(sessionID))
	if err != nil {
		_, _ = r.db.ExecContext(r.ctx, `
DELETE FROM wazync.session_devices WHERE session_id = $1`, sessionID)
		client, handlerID, hasHandler := r.detachClientLocked(sessionID)
		r.mu.Unlock()
		disconnectDetachedClient(client, handlerID, hasHandler)
		return false
	}
	var exists bool
	if err := r.db.QueryRowContext(r.ctx, hasWhatsMeowDeviceSQL, string(plain)).Scan(&exists); err != nil {
		r.mu.Unlock()
		return false
	}
	if exists {
		r.mu.Unlock()
		return true
	}
	_, _ = r.db.ExecContext(r.ctx, `
DELETE FROM wazync.session_devices WHERE session_id = $1`, sessionID)
	client, handlerID, hasHandler := r.detachClientLocked(sessionID)
	r.mu.Unlock()
	disconnectDetachedClient(client, handlerID, hasHandler)
	return false
}

func (r *DeviceResolver) SetEventSink(sink func(string, *whatsmeow.Client, any) bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for sessionID, handlerID := range r.handlers {
		if client := r.clients[sessionID]; client != nil {
			client.RemoveEventHandler(handlerID)
		}
		delete(r.handlers, sessionID)
	}
	r.eventSink = sink
	if sink == nil {
		return
	}
	for sessionID, client := range r.clients {
		resolvedSessionID := sessionID
		resolvedClient := client
		r.handlers[sessionID] = client.AddEventHandlerWithSuccessStatus(func(event any) bool {
			return sink(resolvedSessionID, resolvedClient, event)
		})
	}
}

// Release evicts one client after logout and removes its event handler. A
// subsequent Resolve creates a fresh client from the current device mapping.
func (r *DeviceResolver) Release(sessionID string) {
	r.mu.Lock()
	client, handlerID, hasHandler := r.detachClientLocked(sessionID)
	r.mu.Unlock()
	disconnectDetachedClient(client, handlerID, hasHandler)
}

func (r *DeviceResolver) detachClientLocked(sessionID string) (*whatsmeow.Client, uint32, bool) {
	client := r.clients[sessionID]
	handlerID, hasHandler := r.handlers[sessionID]
	delete(r.handlers, sessionID)
	delete(r.clients, sessionID)
	delete(r.provisioning, sessionID)
	return client, handlerID, hasHandler
}

func (r *DeviceResolver) isProvisioningLocked(sessionID string) bool {
	if r.provisioning[sessionID] {
		return true
	}
	client := r.clients[sessionID]
	return client != nil && client.Store != nil && client.Store.ID == nil
}

func disconnectDetachedClient(client *whatsmeow.Client, handlerID uint32, hasHandler bool) {
	if client == nil {
		return
	}
	if hasHandler {
		client.RemoveEventHandler(handlerID)
	}
	client.Disconnect()
}

// ForgetSession removes only the selected session identity. The device row
// cascades its WhatsMeow secrets; the inbox and application history live in
// Laravel and are deliberately untouched.
func (r *DeviceResolver) ForgetSession(ctx context.Context, sessionID string) error {
	r.mu.Lock()
	client := r.clients[sessionID]
	cachedDeviceJID := ""
	if client != nil && client.Store != nil && client.Store.ID != nil {
		cachedDeviceJID = client.Store.ID.String()
	}
	client, handlerID, hasHandler := r.detachClientLocked(sessionID)
	r.mu.Unlock()
	disconnectDetachedClient(client, handlerID, hasHandler)

	var ciphertext, nonce []byte
	err := r.db.QueryRowContext(ctx, `
SELECT device_jid_cipher, device_jid_nonce
FROM wazync.session_devices
	WHERE session_id = $1`, sessionID).Scan(&ciphertext, &nonce)
	if errors.Is(err, sql.ErrNoRows) {
		if cachedDeviceJID != "" {
			if _, cleanupErr := r.db.ExecContext(ctx, deleteWhatsMeowDeviceSQL, cachedDeviceJID); cleanupErr != nil {
				return fmt.Errorf("delete unmapped WhatsMeow device: %w", cleanupErr)
			}
		}
		return nil
	}
	if err != nil {
		return fmt.Errorf("read session device mapping for logout: %w", err)
	}
	plain, decryptErr := r.box.Open(ciphertext, nonce, []byte(sessionID))
	deviceJID := cachedDeviceJID
	if decryptErr == nil {
		deviceJID = string(plain)
	}
	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin session device cleanup: %w", err)
	}
	defer func() { _ = tx.Rollback() }()
	if deviceJID != "" {
		if _, err := tx.ExecContext(ctx, deleteWhatsMeowDeviceSQL, deviceJID); err != nil {
			return fmt.Errorf("delete WhatsMeow device: %w", err)
		}
	}
	if _, err := tx.ExecContext(ctx, `
DELETE FROM wazync.session_devices WHERE session_id = $1`, sessionID); err != nil {
		return fmt.Errorf("delete session device mapping: %w", err)
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit session device cleanup: %w", err)
	}
	if decryptErr != nil {
		return fmt.Errorf("decrypt session device mapping for logout: %w", decryptErr)
	}
	return nil
}

func (r *DeviceResolver) RecordDevice(ctx context.Context, sessionID string) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	client, ok := r.clients[sessionID]
	if !ok || client.Store.ID == nil {
		return errors.New("paired device is not available")
	}
	deviceJID := client.Store.ID.String()
	var exists bool
	if err := r.db.QueryRowContext(ctx, hasWhatsMeowDeviceSQL, deviceJID).Scan(&exists); err != nil {
		return fmt.Errorf("validate paired WhatsMeow device: %w", err)
	}
	if !exists {
		return errors.New("paired WhatsMeow device is not persisted")
	}
	plain := []byte(deviceJID)
	ciphertext, nonce, err := r.box.Seal(plain, []byte(sessionID))
	if err != nil {
		return err
	}
	deviceHash := r.box.Digest(plain)
	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin session device mapping: %w", err)
	}
	defer func() { _ = tx.Rollback() }()
	// One WhatsApp device_jid belongs to exactly one session (WuzAPI users.jid semantics).
	if _, err := tx.ExecContext(ctx, `
DELETE FROM wazync.session_devices
WHERE device_jid_hash = $1 AND session_id <> $2`, deviceHash, sessionID); err != nil {
		return fmt.Errorf("release previous session device ownership: %w", err)
	}
	if _, err := tx.ExecContext(ctx, `
INSERT INTO wazync.session_devices (
    session_id, device_jid_cipher, device_jid_nonce, device_jid_hash, updated_at
) VALUES ($1, $2, $3, $4, now())
ON CONFLICT (session_id) DO UPDATE SET
    device_jid_cipher = EXCLUDED.device_jid_cipher,
    device_jid_nonce = EXCLUDED.device_jid_nonce,
    device_jid_hash = EXCLUDED.device_jid_hash,
    updated_at = now()`, sessionID, ciphertext, nonce, deviceHash); err != nil {
		return fmt.Errorf("persist session device mapping: %w", err)
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit session device mapping: %w", err)
	}
	delete(r.provisioning, sessionID)
	return nil
}

func (r *DeviceResolver) Close() {
	r.mu.Lock()
	clients := r.clients
	handlers := r.handlers
	r.clients = make(map[string]*whatsmeow.Client)
	r.handlers = make(map[string]uint32)
	r.provisioning = make(map[string]bool)
	r.mu.Unlock()
	for sessionID, client := range clients {
		if handlerID, ok := handlers[sessionID]; ok {
			client.RemoveEventHandler(handlerID)
		}
		client.Disconnect()
	}
	_ = r.container.Close()
}
