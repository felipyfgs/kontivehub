package protocol

import (
	"context"
	"crypto/tls"
	"errors"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waCompanionReg"
	"go.mau.fi/whatsmeow/store"
	"google.golang.org/protobuf/proto"
)

var companionIdentityOnce sync.Once

// configureCompanionIdentity mirrors WuzAPI defaults so QR pairing presents as a
// desktop companion. DeviceProps is process-global in whatsmeow.
func configureCompanionIdentity() {
	companionIdentityOnce.Do(func() {
		store.DeviceProps.Os = proto.String("Mac OS 10")
		store.DeviceProps.PlatformType = waCompanionReg.DeviceProps_DESKTOP.Enum()
	})
}

func configureWhatsMeowClient(client *whatsmeow.Client, lifecycle context.Context, settings ClientSettings) error {
	settings = settings.normalized()
	if err := settings.validate(); err != nil {
		return err
	}
	if lifecycle == nil {
		lifecycle = context.Background()
	}
	configureCompanionIdentity()

	// The gateway lease manager is the only reconnect authority. An upstream
	// reconnect loop could otherwise reopen a stale owner's socket after fencing.
	client.EnableAutoReconnect = false
	client.InitialAutoReconnect = false
	client.DisableLoginAutoReconnect = true
	client.AutoReconnectHook = func(error) bool { return false }
	client.AutoTrustIdentity = false
	client.AutomaticMessageRerequestFromPhone = false
	client.UseRetryMessageStore = false
	client.SynchronousAck = true
	client.EnableDecryptedEventBuffer = false
	client.SendReportingTokens = false
	client.BackgroundEventCtx = lifecycle
	client.SetForceActiveDeliveryReceipts(false)
	client.SetMaxParallelRetryReceiptHandling(settings.MaxParallelRetryHandlers)

	client.SetMediaHTTPClient(newSafeHTTPClient(settings.HTTPTimeout))
	client.SetWebsocketHTTPClient(newSafeHTTPClient(settings.HTTPTimeout))
	client.SetPreLoginHTTPClient(newSafeHTTPClient(settings.HTTPTimeout))
	// Calling with an empty address explicitly disables ProxyFromEnvironment.
	// This makes proxy use an administrative opt-in instead of ambient process state.
	if err := client.SetProxyAddress(settings.ProxyAddress); err != nil {
		return ErrInvalidClientSettings
	}
	return nil
}

func newSafeHTTPClient(timeout time.Duration) *http.Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = nil
	transport.TLSClientConfig = cloneTLSConfig(transport.TLSClientConfig)
	transport.TLSClientConfig.MinVersion = tls.VersionTLS12
	transport.TLSHandshakeTimeout = minPositive(transport.TLSHandshakeTimeout, 10*time.Second)
	transport.ResponseHeaderTimeout = minPositive(transport.ResponseHeaderTimeout, 20*time.Second)
	transport.IdleConnTimeout = minPositive(transport.IdleConnTimeout, 90*time.Second)
	return &http.Client{
		Transport: transport,
		Timeout:   timeout,
		CheckRedirect: func(request *http.Request, via []*http.Request) error {
			if len(via) >= 3 || request.URL.Scheme != "https" || request.URL.User != nil {
				return errors.New("unsafe WhatsApp HTTP redirect")
			}
			return nil
		},
	}
}

func cloneTLSConfig(current *tls.Config) *tls.Config {
	if current == nil {
		return &tls.Config{}
	}
	return current.Clone()
}

func minPositive(current, maximum time.Duration) time.Duration {
	if current <= 0 || current > maximum {
		return maximum
	}
	return current
}

func validateProxyAddress(address string) error {
	if address == "" {
		return nil
	}
	parsed, err := url.Parse(address)
	if err != nil || parsed.Host == "" ||
		(parsed.Scheme != "https" && parsed.Scheme != "socks5") ||
		(parsed.Path != "" && parsed.Path != "/") || parsed.RawQuery != "" || parsed.Fragment != "" ||
		strings.ContainsAny(parsed.Host, "\r\n\t ") {
		return ErrInvalidClientSettings
	}
	return nil
}
