package httpapi

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

func TestCommandRecipientScopeIsRejectedBeforePersistence(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name      string
		recipient string
	}{
		{name: "group", recipient: "120363000000000000@g.us"},
		{name: "community", recipient: "120363000000000001@g.us"},
		{name: "newsletter", recipient: "120363000000000000@newsletter"},
		{name: "broadcast", recipient: "123456789@broadcast"},
		{name: "status", recipient: "status@broadcast"},
		{name: "unknown server", recipient: "123456789@unknown.example"},
	}
	for index, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			persistence := store.NewMemory()
			server := newTestServer(persistence)
			body := []byte(`{"contract_version":"v1","command_id":"command-scope-0001","session_id":"session-scope-0001","type":"MESSAGE_SEND","provider_message_id":"message-scope-0001","payload":{"to":"` + test.recipient + `","kind":"TEXT","text":"blocked"}}`)

			response := performCommand(t, server, body, "nonce-command-scope-000"+string(rune('1'+index)))
			if response.Code != http.StatusUnprocessableEntity ||
				!strings.Contains(response.Body.String(), `"error":"RECIPIENT_SCOPE_NOT_ALLOWED"`) {
				t.Fatalf("unexpected response: %d %s", response.Code, response.Body.String())
			}
			metrics, err := persistence.Metrics(t.Context())
			if err != nil || metrics.PendingCommands != 0 {
				t.Fatalf("rejected command reached ledger: metrics=%+v err=%v", metrics, err)
			}
		})
	}
}

func TestQueryRecipientScopeIsRejectedBeforeExecutor(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	executor := &testQueryExecutor{}
	server := newTestServer(persistence).WithQueryExecutor(executor)
	body := []byte(`{"contract_version":"v1","query_id":"query-scope-0001","session_id":"session-scope-0001","type":"USER_INFO","payload":{"users":["+5511999991234","120363000000000000@newsletter"]}}`)

	response := performQuery(t, server, body, "nonce-query-scope-0001")
	if response.Code != http.StatusUnprocessableEntity ||
		!strings.Contains(response.Body.String(), `"error":"RECIPIENT_SCOPE_NOT_ALLOWED"`) {
		t.Fatalf("unexpected response: %d %s", response.Code, response.Body.String())
	}
	if executor.calls != 0 {
		t.Fatalf("rejected query reached executor: calls=%d", executor.calls)
	}
}

func TestMetricsExposeOnlyAggregateRecipientScopeRejections(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := protocol.NewEventBridge(persistence, nil, 20<<20)
	bridge.Handle("session-metric-scope", nil, &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:   types.NewJID("120363-secret-group", types.GroupServer),
				Sender: types.NewJID("5511999991234", types.DefaultUserServer),
			},
			ID: "provider-metric-scope", Timestamp: time.Now().UTC(),
		},
		Message: &waE2E.Message{Conversation: proto.String("secret body")},
	})
	server := newTestServer(persistence).WithRecipientScopeMetrics(bridge)
	request := httptest.NewRequest(http.MethodGet, "/metrics", nil)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusOK ||
		!strings.Contains(response.Body.String(), "wazync_recipient_scope_rejections_total 1") {
		t.Fatalf("scope metric missing: %d %s", response.Code, response.Body.String())
	}
	for _, forbidden := range []string{"120363-secret-group", "provider-metric-scope", "secret body"} {
		if strings.Contains(response.Body.String(), forbidden) {
			t.Fatalf("scope metric leaked %q: %s", forbidden, response.Body.String())
		}
	}
}
