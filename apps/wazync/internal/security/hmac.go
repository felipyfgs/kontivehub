package security

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const (
	HeaderKeyID     = "X-Communication-Key-Id"
	HeaderTimestamp = "X-Communication-Timestamp"
	HeaderNonce     = "X-Communication-Nonce"
	HeaderSignature = "X-Communication-Signature"
)

var (
	ErrMissingHeaders   = errors.New("missing authentication headers")
	ErrInvalidTimestamp = errors.New("invalid authentication timestamp")
	ErrStaleTimestamp   = errors.New("authentication timestamp outside window")
	ErrInvalidNonce     = errors.New("invalid authentication nonce")
	ErrUnknownKey       = errors.New("unknown authentication key")
	ErrInvalidSignature = errors.New("invalid authentication signature")
	ErrReplay           = errors.New("authentication replay")
	noncePattern        = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$`)
)

type NonceStore interface {
	ClaimNonce(context.Context, string, time.Time) (bool, error)
}

type Verifier struct {
	keys     map[string]string
	window   time.Duration
	nonceTTL time.Duration
	nonces   NonceStore
	now      func() time.Time
}

func NewVerifier(keys map[string]string, window, nonceTTL time.Duration, nonces NonceStore) *Verifier {
	return &Verifier{keys: keys, window: window, nonceTTL: nonceTTL, nonces: nonces, now: time.Now}
}

func (v *Verifier) Verify(ctx context.Context, method, path string, body []byte, headers http.Header) error {
	keyID := strings.TrimSpace(headers.Get(HeaderKeyID))
	timestampValue := strings.TrimSpace(headers.Get(HeaderTimestamp))
	nonce := strings.TrimSpace(headers.Get(HeaderNonce))
	signature := strings.TrimSpace(headers.Get(HeaderSignature))
	if keyID == "" || timestampValue == "" || nonce == "" || signature == "" {
		return ErrMissingHeaders
	}
	if len(timestampValue) != 10 {
		return ErrInvalidTimestamp
	}
	timestamp, err := strconv.ParseInt(timestampValue, 10, 64)
	if err != nil {
		return ErrInvalidTimestamp
	}
	now := v.now()
	requestTime := time.Unix(timestamp, 0)
	if delta := now.Sub(requestTime); delta > v.window || delta < -v.window {
		return ErrStaleTimestamp
	}
	if !noncePattern.MatchString(nonce) {
		return ErrInvalidNonce
	}
	secret, ok := v.keys[keyID]
	if !ok || secret == "" {
		return ErrUnknownKey
	}
	expected := Sign(secret, method, path, body, timestamp, nonce)
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return ErrInvalidSignature
	}
	nonceDigest := sha256.Sum256([]byte(keyID + "|" + nonce))
	claimed, err := v.nonces.ClaimNonce(ctx, hex.EncodeToString(nonceDigest[:]), now.Add(v.nonceTTL))
	if err != nil {
		return fmt.Errorf("claim authentication nonce: %w", err)
	}
	if !claimed {
		return ErrReplay
	}
	return nil
}

func Sign(secret, method, path string, body []byte, timestamp int64, nonce string) string {
	canonical := Canonical(method, path, body, timestamp, nonce)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(canonical))
	return "v1=" + hex.EncodeToString(mac.Sum(nil))
}

func Canonical(method, path string, body []byte, timestamp int64, nonce string) string {
	digest := sha256.Sum256(body)
	return strings.Join([]string{
		strings.ToUpper(strings.TrimSpace(method)),
		path,
		strconv.FormatInt(timestamp, 10),
		nonce,
		hex.EncodeToString(digest[:]),
	}, "\n")
}
