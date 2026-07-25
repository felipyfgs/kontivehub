package media

import (
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
)

func TestFetcherSignsRequestAndVerifiesDocument(t *testing.T) {
	t.Parallel()
	content := []byte("private-document")
	digest := sha256.Sum256(content)
	expectedSHA := hex.EncodeToString(digest[:])
	now := time.Unix(1_800_000_000, 0).UTC()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		timestamp, _ := strconv.ParseInt(r.Header.Get(security.HeaderTimestamp), 10, 64)
		nonce := r.Header.Get(security.HeaderNonce)
		expected := security.Sign("test-secret", r.Method, r.URL.EscapedPath(), nil, timestamp, nonce)
		if r.URL.Path != "/api/internal/v1/communication/gateway/media/command-media-0001" ||
			r.Header.Get(security.HeaderKeyID) != "test-key" ||
			r.Header.Get(security.HeaderSignature) != expected {
			http.Error(w, "invalid request", http.StatusUnauthorized)
			return
		}
		w.Header().Set("X-Content-SHA256", expectedSHA)
		_, _ = w.Write(content)
	}))
	defer server.Close()
	fetcher := NewFetcher(
		server.URL+"/api/internal/v1/communication/gateway/media", "test-key", "test-secret", 1024, server.Client(),
	)
	fetcher.now = func() time.Time { return now }
	got, err := fetcher.Fetch(t.Context(), "command-media-0001", expectedSHA, int64(len(content)))
	if err != nil || string(got) != string(content) {
		t.Fatalf("fetch media: bytes=%q err=%v", got, err)
	}
}
