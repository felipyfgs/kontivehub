package config

import (
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Enabled                bool
	HTTPAddress            string
	DatabaseURL            string
	LaravelEventIngestURL  string
	LaravelMediaSourceURL  string
	CurrentKeyID           string
	CurrentSecret          string
	PreviousKeyID          string
	PreviousSecret         string
	HMACWindow             time.Duration
	NonceTTL               time.Duration
	DataKey                []byte
	MaxBodyBytes           int64
	MaxMediaBytes          int64
	ReplicaID              string
	SessionCapacity        int
	LeaseTTL               time.Duration
	HeartbeatEvery         time.Duration
	SpoolDirectory         string
	WhatsAppConnectTimeout time.Duration
	WhatsAppReadyTimeout   time.Duration
	WhatsAppHTTPTimeout    time.Duration
	WhatsAppProxyURL       string
	WhatsAppRetryHandlers  int64
}

func Load() (Config, error) {
	cfg := Config{
		Enabled:                envBool("WAZYNC_ENABLED", false),
		HTTPAddress:            env("WAZYNC_HTTP_ADDRESS", ":8080"),
		DatabaseURL:            strings.TrimSpace(os.Getenv("WAZYNC_DATABASE_URL")),
		LaravelEventIngestURL:  strings.TrimSpace(os.Getenv("WAZYNC_EVENT_INGEST_URL")),
		LaravelMediaSourceURL:  strings.TrimSpace(os.Getenv("WAZYNC_MEDIA_SOURCE_URL")),
		CurrentKeyID:           strings.TrimSpace(os.Getenv("WAZYNC_HMAC_KEY_ID")),
		CurrentSecret:          os.Getenv("WAZYNC_HMAC_SECRET"),
		PreviousKeyID:          strings.TrimSpace(os.Getenv("WAZYNC_HMAC_PREVIOUS_KEY_ID")),
		PreviousSecret:         os.Getenv("WAZYNC_HMAC_PREVIOUS_SECRET"),
		HMACWindow:             envDuration("WAZYNC_HMAC_WINDOW", 5*time.Minute),
		NonceTTL:               envDuration("WAZYNC_NONCE_TTL", 10*time.Minute),
		MaxBodyBytes:           envInt64("WAZYNC_MAX_BODY_BYTES", 1<<20),
		MaxMediaBytes:          envInt64("WAZYNC_MEDIA_MAX_BYTES", 20<<20),
		ReplicaID:              env("WAZYNC_REPLICA_ID", hostname()),
		SessionCapacity:        envInt("WAZYNC_SESSION_CAPACITY", 250),
		LeaseTTL:               envDuration("WAZYNC_LEASE_TTL", 2*time.Minute),
		HeartbeatEvery:         envDuration("WAZYNC_HEARTBEAT_EVERY", 10*time.Second),
		SpoolDirectory:         env("WAZYNC_SPOOL_DIR", "/var/lib/wazync/spool"),
		WhatsAppConnectTimeout: envDuration("WAZYNC_WHATSAPP_CONNECT_TIMEOUT", 20*time.Second),
		WhatsAppReadyTimeout:   envDuration("WAZYNC_WHATSAPP_READY_TIMEOUT", 30*time.Second),
		WhatsAppHTTPTimeout:    envDuration("WAZYNC_WHATSAPP_HTTP_TIMEOUT", 45*time.Second),
		WhatsAppProxyURL:       strings.TrimSpace(os.Getenv("WAZYNC_WHATSAPP_PROXY_URL")),
		WhatsAppRetryHandlers:  envInt64("WAZYNC_WHATSAPP_RETRY_HANDLERS", 4),
	}

	if raw := strings.TrimSpace(os.Getenv("WAZYNC_DATA_KEY")); raw != "" {
		decoded, err := base64.StdEncoding.DecodeString(raw)
		if err != nil || len(decoded) != 32 {
			return Config{}, errors.New("WAZYNC_DATA_KEY must be 32 bytes encoded as base64")
		}
		cfg.DataKey = decoded
	}

	if cfg.Enabled {
		if cfg.DatabaseURL == "" {
			return Config{}, errors.New("WAZYNC_DATABASE_URL is required when WAZYNC_ENABLED=true")
		}
		if cfg.CurrentKeyID == "" {
			return Config{}, errors.New("WAZYNC_HMAC_KEY_ID is required when WAZYNC_ENABLED=true")
		}
		if len([]byte(cfg.CurrentSecret)) < 32 {
			return Config{}, errors.New("WAZYNC_HMAC_SECRET must be at least 32 bytes when WAZYNC_ENABLED=true")
		}
		if (cfg.PreviousKeyID == "") != (cfg.PreviousSecret == "") {
			return Config{}, errors.New("WAZYNC_HMAC_PREVIOUS_KEY_ID and WAZYNC_HMAC_PREVIOUS_SECRET must be set together when WAZYNC_ENABLED=true")
		}
		if cfg.PreviousSecret != "" && len([]byte(cfg.PreviousSecret)) < 32 {
			return Config{}, errors.New("WAZYNC_HMAC_PREVIOUS_SECRET must be at least 32 bytes when WAZYNC_ENABLED=true")
		}
		if len(cfg.DataKey) != 32 {
			return Config{}, errors.New("WAZYNC_DATA_KEY is required when WAZYNC_ENABLED=true")
		}
		if cfg.LaravelEventIngestURL == "" {
			return Config{}, errors.New("WAZYNC_EVENT_INGEST_URL is required when WAZYNC_ENABLED=true")
		}
		if cfg.LaravelMediaSourceURL == "" {
			return Config{}, errors.New("WAZYNC_MEDIA_SOURCE_URL is required when WAZYNC_ENABLED=true")
		}
	}
	if cfg.SessionCapacity < 1 || cfg.HMACWindow <= 0 || cfg.NonceTTL < cfg.HMACWindow {
		return Config{}, errors.New("invalid capacity or HMAC timing configuration")
	}
	if cfg.WhatsAppConnectTimeout <= 0 || cfg.WhatsAppConnectTimeout > 2*time.Minute ||
		cfg.WhatsAppReadyTimeout <= 0 || cfg.WhatsAppReadyTimeout > 2*time.Minute ||
		cfg.WhatsAppHTTPTimeout <= 0 || cfg.WhatsAppHTTPTimeout > 5*time.Minute ||
		cfg.WhatsAppRetryHandlers < 1 || cfg.WhatsAppRetryHandlers > 32 {
		return Config{}, errors.New("invalid WhatsApp runtime limits")
	}

	return cfg, nil
}

func env(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}

func envBool(name string, fallback bool) bool {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	return err == nil && parsed
}

func envInt(name string, fallback int) int {
	value, err := strconv.Atoi(strings.TrimSpace(os.Getenv(name)))
	if err != nil {
		return fallback
	}
	return value
}

func envInt64(name string, fallback int64) int64 {
	value, err := strconv.ParseInt(strings.TrimSpace(os.Getenv(name)), 10, 64)
	if err != nil {
		return fallback
	}
	return value
}

func envDuration(name string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	parsed, err := time.ParseDuration(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func hostname() string {
	name, err := os.Hostname()
	if err != nil || strings.TrimSpace(name) == "" {
		return fmt.Sprintf("wazync-%d", os.Getpid())
	}
	return name
}
