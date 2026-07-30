package main

import (
	"net/http"
	"testing"
	"time"
)

func TestHTTPServerTimeoutsCoverProfilePictureWithoutRelaxingOtherTransportLimits(t *testing.T) {
	t.Parallel()

	handler := http.HandlerFunc(func(http.ResponseWriter, *http.Request) {})
	server := newHTTPServer("127.0.0.1:0", handler)

	if server.WriteTimeout != 100*time.Second {
		t.Fatalf("write timeout = %s, want 100s", server.WriteTimeout)
	}
	if server.WriteTimeout <= 15*time.Second+80*time.Second {
		t.Fatalf("write timeout %s does not cover request read plus the 80s profile picture deadline", server.WriteTimeout)
	}
	if server.ReadHeaderTimeout != 5*time.Second || server.ReadTimeout != 15*time.Second || server.IdleTimeout != 60*time.Second {
		t.Fatalf(
			"unrelated transport timeouts changed: read_header=%s read=%s idle=%s",
			server.ReadHeaderTimeout,
			server.ReadTimeout,
			server.IdleTimeout,
		)
	}
}
