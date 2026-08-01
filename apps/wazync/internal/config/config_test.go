package config

import (
	"encoding/base64"
	"strings"
	"testing"
	"time"
)

func TestLoadIsFailClosedByDefault(t *testing.T) {
	t.Setenv("WAZYNC_ENABLED", "")
	t.Setenv("WAZYNC_DATABASE_URL", "")
	t.Setenv("WAZYNC_HMAC_KEY_ID", "")
	t.Setenv("WAZYNC_HMAC_SECRET", "")
	t.Setenv("WAZYNC_DATA_KEY", "")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load disabled defaults: %v", err)
	}
	if cfg.Enabled {
		t.Fatal("Wazync must default to disabled")
	}
	if cfg.SpoolDirectory != "/var/lib/wazync/spool" {
		t.Fatalf("unexpected default spool directory: %q", cfg.SpoolDirectory)
	}
}

func TestLoadRejectsEnabledWazyncWithoutSecrets(t *testing.T) {
	t.Setenv("WAZYNC_ENABLED", "true")
	t.Setenv("WAZYNC_DATABASE_URL", "")
	t.Setenv("WAZYNC_HMAC_KEY_ID", "")
	t.Setenv("WAZYNC_HMAC_SECRET", "")
	t.Setenv("WAZYNC_DATA_KEY", "")

	if _, err := Load(); err == nil || !strings.Contains(err.Error(), "WAZYNC_DATABASE_URL") {
		t.Fatal("expected enabled configuration to fail closed")
	}
}

func TestLoadReportsEachMissingRequiredWazyncVariable(t *testing.T) {
	required := []string{
		"WAZYNC_DATABASE_URL",
		"WAZYNC_HMAC_KEY_ID",
		"WAZYNC_HMAC_SECRET",
		"WAZYNC_DATA_KEY",
		"WAZYNC_EVENT_INGEST_URL",
		"WAZYNC_MEDIA_SOURCE_URL",
	}
	for _, variable := range required {
		t.Run(variable, func(t *testing.T) {
			setCompleteEnabledConfiguration(t)
			t.Setenv(variable, "")

			if _, err := Load(); err == nil || !strings.Contains(err.Error(), variable) {
				t.Fatalf("expected error naming %s, got %v", variable, err)
			}
		})
	}
}

func TestLoadRejectsRemovedEndpointVariables(t *testing.T) {
	tests := []struct {
		canonical string
		removed   string
		value     string
	}{
		{
			canonical: "WAZYNC_EVENT_INGEST_URL",
			removed:   "WAZYNC_EVENTS_URL",
			value:     "http://php/api/internal/v1/whatsapp/events",
		},
		{
			canonical: "WAZYNC_MEDIA_SOURCE_URL",
			removed:   "WAZYNC_MEDIA_URL",
			value:     "http://php/api/internal/v1/communication/gateway/media",
		},
	}
	for _, test := range tests {
		t.Run(test.removed, func(t *testing.T) {
			setCompleteEnabledConfiguration(t)
			t.Setenv(test.canonical, "")
			t.Setenv(test.removed, test.value)

			if _, err := Load(); err == nil || !strings.Contains(err.Error(), test.canonical) {
				t.Fatalf("removed endpoint variable must fail closed, got %v", err)
			}
		})
	}
}

func TestLoadRejectsWeakCurrentHMACSecret(t *testing.T) {
	setCompleteEnabledConfiguration(t)
	t.Setenv("WAZYNC_HMAC_SECRET", strings.Repeat("s", 31))

	if _, err := Load(); err == nil || !strings.Contains(err.Error(), "WAZYNC_HMAC_SECRET") {
		t.Fatalf("expected weak current secret to fail closed, got %v", err)
	}
}

func TestLoadRejectsIncompletePreviousHMACRotation(t *testing.T) {
	tests := []struct {
		name           string
		previousKeyID  string
		previousSecret string
	}{
		{name: "key without secret", previousKeyID: "wazync-v0"},
		{name: "secret without key", previousSecret: strings.Repeat("p", 32)},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			setCompleteEnabledConfiguration(t)
			t.Setenv("WAZYNC_HMAC_PREVIOUS_KEY_ID", test.previousKeyID)
			t.Setenv("WAZYNC_HMAC_PREVIOUS_SECRET", test.previousSecret)

			if _, err := Load(); err == nil ||
				!strings.Contains(err.Error(), "WAZYNC_HMAC_PREVIOUS_KEY_ID") ||
				!strings.Contains(err.Error(), "WAZYNC_HMAC_PREVIOUS_SECRET") {
				t.Fatalf("expected incomplete rotation to fail closed, got %v", err)
			}
		})
	}
}

func TestLoadRejectsWeakPreviousHMACSecret(t *testing.T) {
	setCompleteEnabledConfiguration(t)
	t.Setenv("WAZYNC_HMAC_PREVIOUS_KEY_ID", "wazync-v0")
	t.Setenv("WAZYNC_HMAC_PREVIOUS_SECRET", strings.Repeat("p", 31))

	if _, err := Load(); err == nil || !strings.Contains(err.Error(), "WAZYNC_HMAC_PREVIOUS_SECRET") {
		t.Fatalf("expected weak previous secret to fail closed, got %v", err)
	}
}

func TestLoadAcceptsCompleteEnabledWazyncConfiguration(t *testing.T) {
	setCompleteEnabledConfiguration(t)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load enabled config: %v", err)
	}
	if !cfg.Enabled || len(cfg.DataKey) != 32 {
		t.Fatalf("unexpected config: enabled=%v key_length=%d", cfg.Enabled, len(cfg.DataKey))
	}
}

func TestLoadUsesWhatsAppRuntimeVariables(t *testing.T) {
	t.Setenv("WAZYNC_WHATSAPP_CONNECT_TIMEOUT", "21s")
	t.Setenv("WAZYNC_WHATSAPP_READY_TIMEOUT", "31s")
	t.Setenv("WAZYNC_WHATSAPP_HTTP_TIMEOUT", "46s")
	t.Setenv("WAZYNC_WHATSAPP_PROXY_URL", "socks5://proxy.internal:1080")
	t.Setenv("WAZYNC_WHATSAPP_RETRY_HANDLERS", "5")
	t.Setenv("WAZYNC_WA_CONNECT_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_READY_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_HTTP_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_PROXY_URL", "http://.invalid")
	t.Setenv("WAZYNC_WA_RETRY_HANDLERS", "0")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load WhatsApp runtime configuration: %v", err)
	}
	if cfg.WhatsAppConnectTimeout != 21*time.Second ||
		cfg.WhatsAppReadyTimeout != 31*time.Second ||
		cfg.WhatsAppHTTPTimeout != 46*time.Second ||
		cfg.WhatsAppProxyURL != "socks5://proxy.internal:1080" ||
		cfg.WhatsAppRetryHandlers != 5 {
		t.Fatalf("unexpected WhatsApp runtime configuration: %+v", cfg)
	}
}

func TestLoadRejectsRemovedWhatsAppRuntimeVariables(t *testing.T) {
	t.Setenv("WAZYNC_WHATSAPP_CONNECT_TIMEOUT", "")
	t.Setenv("WAZYNC_WHATSAPP_READY_TIMEOUT", "")
	t.Setenv("WAZYNC_WHATSAPP_HTTP_TIMEOUT", "")
	t.Setenv("WAZYNC_WHATSAPP_PROXY_URL", "")
	t.Setenv("WAZYNC_WHATSAPP_RETRY_HANDLERS", "")
	t.Setenv("WAZYNC_WA_CONNECT_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_READY_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_HTTP_TIMEOUT", "0s")
	t.Setenv("WAZYNC_WA_PROXY_URL", "http://.invalid")
	t.Setenv("WAZYNC_WA_RETRY_HANDLERS", "0")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("removed WhatsApp variables must not affect configuration: %v", err)
	}
	if cfg.WhatsAppConnectTimeout != 20*time.Second ||
		cfg.WhatsAppReadyTimeout != 30*time.Second ||
		cfg.WhatsAppHTTPTimeout != 45*time.Second ||
		cfg.WhatsAppProxyURL != "" ||
		cfg.WhatsAppRetryHandlers != 4 {
		t.Fatalf("removed WhatsApp variables were accepted: %+v", cfg)
	}
}

func setCompleteEnabledConfiguration(t *testing.T) {
	t.Helper()
	t.Setenv("WAZYNC_ENABLED", "true")
	t.Setenv("WAZYNC_DATABASE_URL", "postgres://wazync@postgres/nfse")
	t.Setenv("WAZYNC_EVENT_INGEST_URL", "http://php/api/internal/v1/whatsapp/events")
	t.Setenv("WAZYNC_MEDIA_SOURCE_URL", "http://php/api/internal/v1/communication/gateway/media")
	t.Setenv("WAZYNC_HMAC_KEY_ID", "wazync-v1")
	t.Setenv("WAZYNC_HMAC_SECRET", strings.Repeat("s", 32))
	t.Setenv("WAZYNC_HMAC_PREVIOUS_KEY_ID", "")
	t.Setenv("WAZYNC_HMAC_PREVIOUS_SECRET", "")
	t.Setenv("WAZYNC_DATA_KEY", base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", 32))))
}
