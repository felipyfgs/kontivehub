package media

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
)

type Fetcher struct {
	baseURL  string
	keyID    string
	secret   string
	maxBytes int64
	client   *http.Client
	now      func() time.Time
}

func NewFetcher(baseURL, keyID, secret string, maxBytes int64, client *http.Client) *Fetcher {
	if client == nil {
		client = &http.Client{Timeout: 45 * time.Second}
	}
	return &Fetcher{
		baseURL: strings.TrimRight(baseURL, "/"), keyID: keyID, secret: secret,
		maxBytes: maxBytes, client: client, now: time.Now,
	}
}

func (f *Fetcher) Fetch(ctx context.Context, commandID, expectedSHA string, expectedSize int64) ([]byte, error) {
	if f.baseURL == "" || f.keyID == "" || f.secret == "" || f.maxBytes < 1 {
		return nil, errors.New("media fetcher is not configured")
	}
	if expectedSize < 1 || expectedSize > f.maxBytes || len(expectedSHA) != 64 {
		return nil, errors.New("invalid media descriptor")
	}
	endpoint := f.baseURL + "/" + url.PathEscape(commandID)
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	now := f.now().UTC()
	nonce := randomNonce()
	request.Header.Set(security.HeaderKeyID, f.keyID)
	request.Header.Set(security.HeaderTimestamp, fmt.Sprintf("%d", now.Unix()))
	request.Header.Set(security.HeaderNonce, nonce)
	request.Header.Set(security.HeaderSignature, security.Sign(
		f.secret, request.Method, request.URL.EscapedPath(), nil, now.Unix(), nonce,
	))
	response, err := f.client.Do(request)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4<<10))
		return nil, fmt.Errorf("Laravel media endpoint returned status %d", response.StatusCode)
	}
	bytes, err := io.ReadAll(io.LimitReader(response.Body, f.maxBytes+1))
	if err != nil {
		return nil, err
	}
	if int64(len(bytes)) != expectedSize || int64(len(bytes)) > f.maxBytes {
		return nil, errors.New("media size mismatch")
	}
	digest := sha256.Sum256(bytes)
	if !strings.EqualFold(hex.EncodeToString(digest[:]), expectedSHA) {
		return nil, errors.New("media digest mismatch")
	}
	if header := response.Header.Get("X-Content-SHA256"); header != "" && !strings.EqualFold(header, expectedSHA) {
		return nil, errors.New("media response digest mismatch")
	}
	return bytes, nil
}

func randomNonce() string {
	value := make([]byte, 16)
	_, _ = rand.Read(value)
	return hex.EncodeToString(value)
}
