package config

import (
	"encoding/base64"
	"strings"
	"testing"
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
		"WAZYNC_EVENTS_URL",
		"WAZYNC_MEDIA_URL",
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

func TestLoadDoesNotReadLegacyEnvironmentAliases(t *testing.T) {
	t.Setenv("WAZYNC_ENABLED", "")
	t.Setenv("WAZYNC_DATABASE_URL", "")
	t.Setenv("WAZYNC_HMAC_KEY_ID", "")
	t.Setenv("WAZYNC_HMAC_SECRET", "")
	t.Setenv("WAZYNC_DATA_KEY", "")
	legacyPrefix := "WHATSAPP_" + "GATEWAY_"
	t.Setenv(legacyPrefix+"ENABLED", "true")
	t.Setenv(legacyPrefix+"DATABASE_URL", "postgres://legacy@postgres/nfse")
	t.Setenv(legacyPrefix+"HMAC_KEY_ID", "legacy-v1")
	t.Setenv(legacyPrefix+"HMAC_SECRET", strings.Repeat("s", 32))
	t.Setenv(legacyPrefix+"DATA_KEY", base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", 32))))

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load without canonical configuration: %v", err)
	}
	if cfg.Enabled || cfg.DatabaseURL != "" || cfg.CurrentKeyID != "" || cfg.CurrentSecret != "" || len(cfg.DataKey) != 0 {
		t.Fatalf("legacy environment affected config: %+v", cfg)
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

func setCompleteEnabledConfiguration(t *testing.T) {
	t.Helper()
	t.Setenv("WAZYNC_ENABLED", "true")
	t.Setenv("WAZYNC_DATABASE_URL", "postgres://wazync@postgres/nfse")
	t.Setenv("WAZYNC_EVENTS_URL", "http://php/api/internal/v1/whatsapp/events")
	t.Setenv("WAZYNC_MEDIA_URL", "http://php/api/internal/v1/communication/gateway/media")
	t.Setenv("WAZYNC_HMAC_KEY_ID", "wazync-v1")
	t.Setenv("WAZYNC_HMAC_SECRET", strings.Repeat("s", 32))
	t.Setenv("WAZYNC_HMAC_PREVIOUS_KEY_ID", "")
	t.Setenv("WAZYNC_HMAC_PREVIOUS_SECRET", "")
	t.Setenv("WAZYNC_DATA_KEY", base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", 32))))
}
