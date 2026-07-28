package protocol

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"
	"unicode"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

const (
	defaultConnectTimeout = 20 * time.Second
	defaultReadyTimeout   = 30 * time.Second
	defaultHTTPTimeout    = 45 * time.Second
	phonePairingTTL       = 2 * time.Minute
	maxPasskeyPartBytes   = 64 << 10
)

var (
	ErrSessionClientUnsupported = errors.New("WhatsApp session client does not support the required operation")
	ErrSessionConnect           = errors.New("WhatsApp session connection failed")
	ErrSessionNotReady          = errors.New("WhatsApp session did not become ready")
	ErrSessionLogout            = errors.New("WhatsApp session logout failed")
	ErrSessionReset             = errors.New("WhatsApp session reset failed")
	ErrSessionNotConnected      = errors.New("WhatsApp session is not connected")
	ErrInvalidPairingPhone      = errors.New("invalid international pairing phone")
	ErrPairingFailed            = errors.New("WhatsApp pairing failed")
	ErrInvalidPasskeyResponse   = errors.New("invalid passkey response")
	ErrPasskeyDeclined          = errors.New("passkey confirmation declined")
	ErrInvalidClientSettings    = errors.New("invalid WhatsApp client settings")
)

// ClientSettings are internal runtime limits. They are never accepted in the
// public gateway contract and are applied before the websocket is opened.
type ClientSettings struct {
	ConnectTimeout           time.Duration
	ReadyTimeout             time.Duration
	HTTPTimeout              time.Duration
	ProxyAddress             string
	MaxParallelRetryHandlers int64
}

func (s ClientSettings) normalized() ClientSettings {
	if s.ConnectTimeout == 0 {
		s.ConnectTimeout = defaultConnectTimeout
	}
	if s.ReadyTimeout == 0 {
		s.ReadyTimeout = defaultReadyTimeout
	}
	if s.HTTPTimeout == 0 {
		s.HTTPTimeout = defaultHTTPTimeout
	}
	if s.MaxParallelRetryHandlers == 0 {
		s.MaxParallelRetryHandlers = 4
	}
	s.ProxyAddress = strings.TrimSpace(s.ProxyAddress)
	return s
}

func (s ClientSettings) validate() error {
	s = s.normalized()
	if s.ConnectTimeout <= 0 || s.ConnectTimeout > 2*time.Minute ||
		s.ReadyTimeout <= 0 || s.ReadyTimeout > 2*time.Minute ||
		s.HTTPTimeout <= 0 || s.HTTPTimeout > 5*time.Minute ||
		s.MaxParallelRetryHandlers < 1 || s.MaxParallelRetryHandlers > 32 {
		return ErrInvalidClientSettings
	}
	if err := validateProxyAddress(s.ProxyAddress); err != nil {
		return err
	}
	return nil
}

type sessionWhatsMeowClient interface {
	WhatsMeowClient
	ConnectContext(context.Context) error
	WaitForConnection(time.Duration) bool
	IsLoggedIn() bool
	ResetConnection()
	PairPhone(context.Context, string, bool, whatsmeow.PairClientType, string) (string, error)
	SendPasskeyResponse(context.Context, *types.WebAuthnResponse) error
	SendPasskeyConfirmation(context.Context) error
	SetPassive(context.Context, bool) error
}

var _ sessionWhatsMeowClient = (*whatsmeow.Client)(nil)

// ClientReleaser lets the adapter evict a logged-out client and its handler.
type ClientReleaser interface {
	Release(sessionID string)
}

// ClientPeeker inspects a cached client without creating a provisional NewDevice.
type ClientPeeker interface {
	Peek(sessionID string) (WhatsMeowClient, bool)
}

type SessionCredentialStore interface {
	ForgetSession(context.Context, string) error
}

type pairedDeviceResolver interface {
	HasDevice(sessionID string) bool
}

type SessionState struct {
	Connected bool `json:"connected"`
	LoggedIn  bool `json:"logged_in"`
	Ready     bool `json:"ready"`
	Passive   bool `json:"passive"`
}

func newWhatsMeowAdapter(clients ClientResolver, settings ...ClientSettings) *WhatsMeowAdapter {
	configured := ClientSettings{}.normalized()
	if len(settings) > 0 {
		configured = settings[0].normalized()
	}
	return &WhatsMeowAdapter{
		clients: clients, settings: configured, passive: make(map[string]bool),
	}
}

func (a *WhatsMeowAdapter) ConnectContext(ctx context.Context, sessionID string) error {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return err
	}
	if client.IsConnected() && client.IsLoggedIn() {
		return nil
	}
	if !client.IsConnected() {
		connectCtx, cancel := boundedContext(ctx, a.settings.ConnectTimeout)
		err = connectWithControlContext(connectCtx, client)
		cancel()
		if err != nil {
			client.Disconnect()
			wrapped := fmt.Errorf("%w: %v", ErrSessionConnect, err)
			slog.Warn("whatsapp session connect failed",
				"session_id", sessionID,
				"error_class", "SESSION_CONNECT",
				"cause", sanitizeConnectCause(err),
			)
			return wrapped
		}
	}
	if err := waitForConnection(ctx, client, a.settings.ReadyTimeout); err != nil {
		client.Disconnect()
		wrapped := fmt.Errorf("%w: %v", ErrSessionNotReady, err)
		slog.Warn("whatsapp session ready wait failed",
			"session_id", sessionID,
			"error_class", "SESSION_NOT_READY",
			"cause", sanitizeConnectCause(err),
			"connected", client.IsConnected(),
			"logged_in", client.IsLoggedIn(),
		)
		return wrapped
	}
	return nil
}

// Ready is the lease manager's health seam. It deliberately exposes only the
// boolean needed for recovery and never the device JID or upstream state.
func (a *WhatsMeowAdapter) Ready(sessionID string) bool {
	state, err := a.State(sessionID)
	return err == nil && state.Ready
}

func (a *WhatsMeowAdapter) CanReconnect(sessionID string) bool {
	resolver, ok := a.clients.(pairedDeviceResolver)
	return ok && resolver.HasDevice(sessionID)
}

func (a *WhatsMeowAdapter) HasCredentials(sessionID string) bool {
	return a.CanReconnect(sessionID)
}

func (a *WhatsMeowAdapter) ForgetSession(ctx context.Context, sessionID string) error {
	a.clearSessionState(sessionID)
	if credentials, ok := a.clients.(SessionCredentialStore); ok {
		return credentials.ForgetSession(ctx, sessionID)
	}
	if releaser, ok := a.clients.(ClientReleaser); ok {
		releaser.Release(sessionID)
	}
	return nil
}

func (a *WhatsMeowAdapter) State(sessionID string) (SessionState, error) {
	client, ok := a.peekSessionClient(sessionID)
	if !ok {
		return SessionState{}, nil
	}
	connected := client.IsConnected()
	loggedIn := client.IsLoggedIn()
	a.stateMu.RLock()
	passive := a.passive[sessionID]
	a.stateMu.RUnlock()
	return SessionState{
		Connected: connected,
		LoggedIn:  loggedIn,
		Ready:     connected && loggedIn,
		Passive:   passive,
	}, nil
}

func (a *WhatsMeowAdapter) Reset(ctx context.Context, sessionID string) error {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return err
	}
	if !client.IsConnected() {
		return ErrSessionNotConnected
	}

	// Upstream auto-reconnect is deliberately disabled because it is unaware of
	// the gateway lease. ResetConnection tears down the socket; this owner then
	// reconnects synchronously under the caller context.
	client.ResetConnection()
	client.Disconnect()
	if err := a.ConnectContext(ctx, sessionID); err != nil {
		return ErrSessionReset
	}
	return nil
}

func (a *WhatsMeowAdapter) SetPassive(ctx context.Context, sessionID string, passive bool) error {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return err
	}
	if !client.IsConnected() || !client.IsLoggedIn() {
		return ErrSessionNotReady
	}
	if err := client.SetPassive(ctx, passive); err != nil {
		return ErrSessionConnect
	}
	a.stateMu.Lock()
	a.passive[sessionID] = passive
	a.stateMu.Unlock()
	return nil
}

func (a *WhatsMeowAdapter) StartPairing(ctx context.Context, sessionID string) (<-chan domain.PairingUpdate, error) {
	client, updates, err := a.openPairingChannel(ctx, sessionID)
	if err != nil {
		return nil, err
	}
	_ = client
	result := make(chan domain.PairingUpdate, 8)
	go forwardPairingUpdates(ctx, updates, result, nil)
	return result, nil
}

func (a *WhatsMeowAdapter) StartPhonePairing(
	ctx context.Context,
	sessionID, phone string,
	showPushNotification bool,
) (<-chan domain.PairingUpdate, error) {
	normalized, err := normalizePairingPhone(phone)
	if err != nil {
		return nil, err
	}
	client, updates, err := a.openPairingChannel(ctx, sessionID)
	if err != nil {
		return nil, err
	}
	result := make(chan domain.PairingUpdate, 8)
	requestCode := func() (domain.PairingUpdate, error) {
		code, pairErr := client.PairPhone(
			ctx, normalized, showPushNotification, whatsmeow.PairClientChrome, "Chrome (Linux)",
		)
		if pairErr != nil || strings.TrimSpace(code) == "" {
			return domain.PairingUpdate{}, ErrPairingFailed
		}
		return domain.PairingUpdate{
			Event: "phone-code", Code: code, ExpiresAt: time.Now().UTC().Add(phonePairingTTL),
		}, nil
	}
	go forwardPairingUpdates(ctx, updates, result, requestCode)
	return result, nil
}

func (a *WhatsMeowAdapter) RespondPasskey(
	ctx context.Context,
	sessionID string,
	payload domain.PasskeyResponsePayload,
) error {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return err
	}
	if !client.IsConnected() || client.IsLoggedIn() {
		return ErrSessionNotConnected
	}
	response, err := webAuthnResponse(payload)
	if err != nil {
		return err
	}
	if err := client.SendPasskeyResponse(ctx, response); err != nil {
		return ErrPairingFailed
	}
	return nil
}

func (a *WhatsMeowAdapter) ConfirmPasskey(ctx context.Context, sessionID string, confirm bool) error {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return err
	}
	if !confirm {
		client.Disconnect()
		return ErrPasskeyDeclined
	}
	if !client.IsConnected() || client.IsLoggedIn() {
		return ErrSessionNotConnected
	}
	if err := client.SendPasskeyConfirmation(ctx); err != nil {
		return ErrPairingFailed
	}
	return nil
}

func (a *WhatsMeowAdapter) openPairingChannel(
	ctx context.Context,
	sessionID string,
) (sessionWhatsMeowClient, <-chan whatsmeow.QRChannelItem, error) {
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return nil, nil, err
	}
	if client.IsLoggedIn() {
		return nil, nil, domain.ErrSessionAlreadyPaired
	}
	if client.IsConnected() {
		client.Disconnect()
	}
	updates, err := client.GetQRChannel(ctx)
	if err != nil {
		if errors.Is(err, whatsmeow.ErrQRStoreContainsID) {
			return nil, nil, domain.ErrSessionAlreadyPaired
		}
		return nil, nil, ErrPairingFailed
	}
	connectCtx, cancel := boundedContext(ctx, a.settings.ConnectTimeout)
	err = connectWithControlContext(connectCtx, client)
	cancel()
	if err != nil {
		client.Disconnect()
		return nil, nil, ErrSessionConnect
	}
	return client, updates, nil
}

// connectWithControlContext bounds the attempt without handing the short-lived
// context to WhatsMeow. Connect uses the client's BackgroundEventCtx, which is
// the gateway lifecycle context configured by DeviceResolver.
func connectWithControlContext(ctx context.Context, client sessionWhatsMeowClient) error {
	result := make(chan error, 1)
	go func() {
		result <- client.Connect()
	}()
	select {
	case err := <-result:
		return err
	case <-ctx.Done():
		// Interrupt in-flight Connect and return control promptly. If Connect
		// ignores the disconnect and succeeds late, tear that socket down too.
		client.Disconnect()
		go func() {
			<-result
			client.Disconnect()
		}()
		return ctx.Err()
	}
}

func (a *WhatsMeowAdapter) resolveSessionClient(sessionID string) (sessionWhatsMeowClient, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, err
	}
	sessionClient, ok := client.(sessionWhatsMeowClient)
	if !ok {
		return nil, ErrSessionClientUnsupported
	}
	return sessionClient, nil
}

func (a *WhatsMeowAdapter) peekSessionClient(sessionID string) (sessionWhatsMeowClient, bool) {
	if peeker, ok := a.clients.(ClientPeeker); ok {
		client, found := peeker.Peek(sessionID)
		if !found {
			return nil, false
		}
		sessionClient, ok := client.(sessionWhatsMeowClient)
		return sessionClient, ok
	}
	client, err := a.resolveSessionClient(sessionID)
	if err != nil {
		return nil, false
	}
	return client, true
}

func (a *WhatsMeowAdapter) clearSessionState(sessionID string) {
	a.stateMu.Lock()
	delete(a.passive, sessionID)
	a.stateMu.Unlock()
	a.clearMediaRetrySession(sessionID)
}

func waitForConnection(ctx context.Context, client sessionWhatsMeowClient, timeout time.Duration) error {
	waitCtx, cancel := boundedContext(ctx, timeout)
	defer cancel()
	remaining := timeout
	if deadline, ok := waitCtx.Deadline(); ok {
		remaining = time.Until(deadline)
		if remaining <= 0 {
			return fmt.Errorf("%w: ready deadline already elapsed", ErrSessionNotReady)
		}
	}
	result := make(chan bool, 1)
	go func() {
		result <- client.WaitForConnection(remaining)
	}()
	select {
	case ready := <-result:
		if !ready || !client.IsConnected() || !client.IsLoggedIn() {
			return fmt.Errorf(
				"%w: ready=%v connected=%v logged_in=%v",
				ErrSessionNotReady, ready, client.IsConnected(), client.IsLoggedIn(),
			)
		}
		return nil
	case <-waitCtx.Done():
		return fmt.Errorf("%w: %v", ErrSessionNotReady, waitCtx.Err())
	}
}

func sanitizeConnectCause(err error) string {
	if err == nil {
		return ""
	}
	message := strings.ToLower(err.Error())
	switch {
	case strings.Contains(message, "timeout") || errors.Is(err, context.DeadlineExceeded):
		return "timeout"
	case strings.Contains(message, "canceled") || errors.Is(err, context.Canceled):
		return "canceled"
	case strings.Contains(message, "logged") || strings.Contains(message, "login"):
		return "not_logged_in"
	case strings.Contains(message, "websocket") || strings.Contains(message, "socket"):
		return "socket"
	case strings.Contains(message, "tls") || strings.Contains(message, "x509"):
		return "tls"
	case strings.Contains(message, "refused") || strings.Contains(message, "connection"):
		return "connection"
	default:
		return "upstream"
	}
}

func boundedContext(parent context.Context, timeout time.Duration) (context.Context, context.CancelFunc) {
	if deadline, ok := parent.Deadline(); ok && time.Until(deadline) <= timeout {
		return context.WithCancel(parent)
	}
	return context.WithTimeout(parent, timeout)
}

func forwardPairingUpdates(
	ctx context.Context,
	updates <-chan whatsmeow.QRChannelItem,
	result chan<- domain.PairingUpdate,
	requestPhoneCode func() (domain.PairingUpdate, error),
) {
	defer close(result)
	phoneCodeRequested := false
	for {
		var update whatsmeow.QRChannelItem
		var ok bool
		select {
		case <-ctx.Done():
			return
		case update, ok = <-updates:
			if !ok {
				return
			}
		}
		if requestPhoneCode != nil && update.Event == whatsmeow.QRChannelEventCode {
			if phoneCodeRequested {
				continue
			}
			phoneCodeRequested = true
			mapped, err := requestPhoneCode()
			if err != nil {
				mapped = domain.PairingUpdate{Event: "error", ErrorCode: "PAIRING_FAILED"}
			}
			if !sendPairingUpdate(ctx, result, mapped) || err != nil {
				return
			}
			continue
		}
		if requestPhoneCode != nil && update.Event == whatsmeow.QRChannelEventCode {
			continue
		}
		if !sendPairingUpdate(ctx, result, sanitizePairingUpdate(update)) {
			return
		}
	}
}

func sendPairingUpdate(ctx context.Context, result chan<- domain.PairingUpdate, update domain.PairingUpdate) bool {
	select {
	case result <- update:
		return true
	case <-ctx.Done():
		return false
	}
}

func sanitizePairingUpdate(update whatsmeow.QRChannelItem) domain.PairingUpdate {
	mapped := domain.PairingUpdate{Event: update.Event}
	if update.Code != "" {
		mapped.Code = update.Code
		mapped.ExpiresAt = time.Now().UTC().Add(update.Timeout)
	}
	if update.Error != nil {
		mapped.Code = ""
		mapped.ErrorCode = "PAIRING_FAILED"
	}
	if update.PasskeyRequest != nil && update.PasskeyRequest.PublicKey != nil {
		mapped.PasskeyRequest = sanitizePasskeyRequest(update.PasskeyRequest.PublicKey)
		if mapped.PasskeyRequest == nil {
			mapped.Event = "error"
			mapped.ErrorCode = "PASSKEY_REQUEST_INVALID"
		}
	}
	if update.PasskeyConfirmation != nil {
		mapped.Code = update.PasskeyConfirmation.Code
		mapped.ExpiresAt = time.Now().UTC().Add(5 * time.Minute)
	}
	return mapped
}

func sanitizePasskeyRequest(request *types.WebAuthnPublicKey) *domain.PasskeyRequest {
	challenge := []byte(request.Challenge)
	if len(challenge) == 0 || len(challenge) > maxPasskeyPartBytes ||
		request.Timeout <= 0 || request.Timeout > int((5*time.Minute)/time.Millisecond) ||
		len(request.RelyingPartID) == 0 || len(request.RelyingPartID) > 253 {
		return nil
	}
	digest := sha256.Sum256(challenge)
	result := &domain.PasskeyRequest{
		RequestID:        "passkey-" + hex.EncodeToString(digest[:8]),
		Challenge:        base64.RawURLEncoding.EncodeToString(challenge),
		TimeoutMS:        request.Timeout,
		RelyingPartyID:   request.RelyingPartID,
		UserVerification: request.UserVerification,
	}
	for _, credential := range request.AllowCredentials {
		if len(credential.ID) == 0 || len(credential.ID) > maxPasskeyPartBytes || len(result.AllowedCredential) >= 32 {
			continue
		}
		item := domain.PasskeyCredential{
			ID: base64.RawURLEncoding.EncodeToString(credential.ID), Type: credential.Type,
		}
		for _, transport := range credential.Transports {
			switch transport {
			case "usb", "nfc", "ble", "internal", "hybrid":
				item.Transports = append(item.Transports, transport)
			}
		}
		result.AllowedCredential = append(result.AllowedCredential, item)
	}
	return result
}

func normalizePairingPhone(phone string) (string, error) {
	phone = strings.TrimSpace(phone)
	phone = strings.TrimPrefix(phone, "+")
	if len(phone) < 8 || len(phone) > 15 || strings.HasPrefix(phone, "0") {
		return "", ErrInvalidPairingPhone
	}
	for _, character := range phone {
		if !unicode.IsDigit(character) || character > unicode.MaxASCII {
			return "", ErrInvalidPairingPhone
		}
	}
	return phone, nil
}

func webAuthnResponse(payload domain.PasskeyResponsePayload) (*types.WebAuthnResponse, error) {
	if strings.TrimSpace(payload.ID) == "" || len(payload.ID) > maxPasskeyPartBytes {
		return nil, ErrInvalidPasskeyResponse
	}
	rawID, err := decodePasskeyPart(payload.ID)
	if err != nil {
		return nil, err
	}
	clientData, err := decodePasskeyPart(payload.ClientDataJSON)
	if err != nil {
		return nil, err
	}
	authenticator, err := decodePasskeyPart(payload.Authenticator)
	if err != nil {
		return nil, err
	}
	signature, err := decodePasskeyPart(payload.Signature)
	if err != nil {
		return nil, err
	}
	return &types.WebAuthnResponse{
		ID: payload.ID, RawID: rawID, Type: "public-key",
		Response: types.WebAuthnResponseData{
			ClientDataJSON: clientData, AuthenticatorData: authenticator, Signature: signature,
		},
	}, nil
}

func decodePasskeyPart(value string) ([]byte, error) {
	if value == "" || len(value) > maxPasskeyPartBytes*2 {
		return nil, ErrInvalidPasskeyResponse
	}
	decoded, err := base64.RawURLEncoding.DecodeString(value)
	if err != nil || len(decoded) == 0 || len(decoded) > maxPasskeyPartBytes {
		return nil, ErrInvalidPasskeyResponse
	}
	return decoded, nil
}
