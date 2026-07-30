package httpapi

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type Server struct {
	enabled      bool
	maxBodyBytes int64
	store        store.Store
	verifier     *security.Verifier
	media        interface {
		Reader(context.Context, string) (io.ReadCloser, error)
	}
	queries  QueryExecutor
	sessions interface {
		State(string) (protocol.SessionState, error)
		HasCredentials(string) bool
	}
	scopeMetrics interface {
		RejectedScopeCount() uint64
	}
	mux *http.ServeMux
}

type QueryExecutor interface {
	Execute(context.Context, domain.Query) (any, error)
}

func New(enabled bool, maxBodyBytes int64, persistence store.Store, verifier *security.Verifier) *Server {
	server := &Server{
		enabled:      enabled,
		maxBodyBytes: maxBodyBytes,
		store:        persistence,
		verifier:     verifier,
		mux:          http.NewServeMux(),
	}
	server.mux.HandleFunc("POST /internal/v1/commands", server.acceptCommand)
	server.mux.HandleFunc("POST /internal/v1/queries", server.executeQuery)
	server.mux.HandleFunc("GET /internal/v1/sessions/{sessionID}", server.sessionStatus)
	server.mux.HandleFunc("GET /internal/v1/media/{spoolID}", server.downloadMedia)
	server.mux.HandleFunc("GET /healthz", server.health)
	server.mux.HandleFunc("GET /metrics", server.metrics)
	return server
}

func (s *Server) WithQueryExecutor(executor QueryExecutor) *Server {
	s.queries = executor
	return s
}

func (s *Server) WithSessionInspector(inspector interface {
	State(string) (protocol.SessionState, error)
	HasCredentials(string) bool
}) *Server {
	s.sessions = inspector
	return s
}

func (s *Server) WithRecipientScopeMetrics(metrics interface {
	RejectedScopeCount() uint64
}) *Server {
	s.scopeMetrics = metrics
	return s
}

func (s *Server) WithMediaStore(media interface {
	Reader(context.Context, string) (io.ReadCloser, error)
}) *Server {
	s.media = media
	return s
}

func (s *Server) downloadMedia(w http.ResponseWriter, r *http.Request) {
	if !s.enabled {
		writeError(w, http.StatusServiceUnavailable, "GATEWAY_DISABLED")
		return
	}
	if err := s.verifier.Verify(r.Context(), r.Method, r.URL.EscapedPath(), nil, r.Header); err != nil {
		writeError(w, http.StatusUnauthorized, "INVALID_INTERNAL_SIGNATURE")
		return
	}
	if s.media == nil {
		writeError(w, http.StatusServiceUnavailable, "MEDIA_SPOOL_UNAVAILABLE")
		return
	}
	reader, err := s.media.Reader(r.Context(), r.PathValue("spoolID"))
	if err != nil {
		writeError(w, http.StatusNotFound, "MEDIA_NOT_FOUND")
		return
	}
	defer reader.Close()

	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("Content-Disposition", "attachment")
	if _, err := io.Copy(w, reader); err != nil {
		return
	}
}

func (s *Server) sessionStatus(w http.ResponseWriter, r *http.Request) {
	if !s.enabled {
		writeError(w, http.StatusServiceUnavailable, "GATEWAY_DISABLED")
		return
	}
	if err := s.verifier.Verify(r.Context(), r.Method, r.URL.EscapedPath(), nil, r.Header); err != nil {
		writeError(w, http.StatusUnauthorized, "INVALID_INTERNAL_SIGNATURE")
		return
	}
	session, err := s.store.GetSession(r.Context(), r.PathValue("sessionID"))
	if errors.Is(err, domain.ErrNotFound) {
		session = domain.Session{
			SessionID: r.PathValue("sessionID"),
			Status:    domain.SessionDisconnected,
		}
		err = nil
	}
	if err != nil {
		writeError(w, http.StatusServiceUnavailable, "SESSION_STORE_UNAVAILABLE")
		return
	}
	state := protocol.SessionState{}
	hasCredentials := false
	if s.sessions != nil {
		hasCredentials = s.sessions.HasCredentials(session.SessionID)
		if inspected, inspectErr := s.sessions.State(session.SessionID); inspectErr == nil {
			state = inspected
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"session_id":        session.SessionID,
		"status":            session.Status,
		"desired_connected": session.DesiredConnected,
		"reconnect_count":   session.ReconnectCount,
		"connected":         state.Connected,
		"logged_in":         state.LoggedIn,
		"ready":             state.Ready,
		"has_credentials":   hasCredentials,
	})
}

func (s *Server) Handler() http.Handler {
	return s.securityHeaders(s.mux)
}

func (s *Server) acceptCommand(w http.ResponseWriter, r *http.Request) {
	if !s.enabled {
		writeError(w, http.StatusServiceUnavailable, "GATEWAY_DISABLED")
		return
	}
	body, err := readBody(r.Body, s.maxBodyBytes)
	if err != nil {
		writeError(w, http.StatusRequestEntityTooLarge, "BODY_TOO_LARGE")
		return
	}
	if err := s.verifier.Verify(r.Context(), r.Method, r.URL.EscapedPath(), body, r.Header); err != nil {
		writeError(w, http.StatusUnauthorized, "INVALID_INTERNAL_SIGNATURE")
		return
	}

	var command domain.Command
	if err := decodeStrict(body, &command); err != nil || !command.Valid() || command.ValidatePayload() != nil {
		writeError(w, http.StatusUnprocessableEntity, "INVALID_COMMAND")
		return
	}
	if err := protocol.ValidateCommandRecipientScope(command); err != nil {
		writeRecipientError(w, err, "INVALID_COMMAND")
		return
	}
	command.Digest, err = canonicalDigest(body)
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, "INVALID_COMMAND")
		return
	}
	command.AcceptedAt = time.Now().UTC()

	duplicate, err := s.store.AcceptCommand(r.Context(), command)
	if errors.Is(err, domain.ErrDigestConflict) {
		writeError(w, http.StatusConflict, "COMMAND_DIGEST_CONFLICT")
		return
	}
	if err != nil {
		writeError(w, http.StatusServiceUnavailable, "COMMAND_PERSISTENCE_UNAVAILABLE")
		return
	}

	writeJSON(w, http.StatusAccepted, map[string]any{
		"command_id": command.CommandID,
		"status":     "ACCEPTED",
		"duplicate":  duplicate,
	})
}

func (s *Server) executeQuery(w http.ResponseWriter, r *http.Request) {
	if !s.enabled {
		writeError(w, http.StatusServiceUnavailable, "GATEWAY_DISABLED")
		return
	}
	body, err := readBody(r.Body, s.maxBodyBytes)
	if err != nil {
		writeError(w, http.StatusRequestEntityTooLarge, "BODY_TOO_LARGE")
		return
	}
	if err := s.verifier.Verify(r.Context(), r.Method, r.URL.EscapedPath(), body, r.Header); err != nil {
		writeError(w, http.StatusUnauthorized, "INVALID_INTERNAL_SIGNATURE")
		return
	}

	var query domain.Query
	if err := decodeStrict(body, &query); err != nil || !query.Valid() || query.ValidatePayload() != nil {
		writeError(w, http.StatusUnprocessableEntity, "INVALID_QUERY")
		return
	}
	if err := protocol.ValidateQueryRecipientScope(query); err != nil {
		writeRecipientError(w, err, "INVALID_QUERY")
		return
	}
	if s.queries == nil {
		writeError(w, http.StatusServiceUnavailable, "QUERY_EXECUTOR_UNAVAILABLE")
		return
	}
	result, err := s.queries.Execute(r.Context(), query)
	if errors.Is(err, domain.ErrProfilePictureHidden) {
		writeError(w, http.StatusForbidden, "PROFILE_PICTURE_PRIVACY")
		return
	}
	if errors.Is(err, domain.ErrProfilePictureNotSet) {
		writeError(w, http.StatusNotFound, "PROFILE_PICTURE_NOT_FOUND")
		return
	}
	if errors.Is(err, domain.ErrNotFound) {
		writeError(w, http.StatusNotFound, "QUERY_TARGET_NOT_FOUND")
		return
	}
	if err != nil {
		writeError(w, http.StatusBadGateway, "QUERY_EXECUTION_FAILED")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"contract_version": "v1",
		"query_id":         query.QueryID,
		"result":           result,
	})
}

func writeRecipientError(w http.ResponseWriter, err error, fallback string) {
	switch {
	case errors.Is(err, protocol.ErrRecipientScopeNotAllowed):
		writeError(w, http.StatusUnprocessableEntity, "RECIPIENT_SCOPE_NOT_ALLOWED")
	case errors.Is(err, protocol.ErrRecipientInvalid):
		writeError(w, http.StatusUnprocessableEntity, "RECIPIENT_INVALID")
	default:
		writeError(w, http.StatusUnprocessableEntity, fallback)
	}
}

func (s *Server) health(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if err := s.store.Ping(ctx); err != nil {
		writeJSON(w, http.StatusServiceUnavailable, domain.Health{Status: "degraded", Enabled: s.enabled, Store: "unavailable"})
		return
	}
	metrics, err := s.store.Metrics(ctx)
	if err != nil {
		writeJSON(w, http.StatusServiceUnavailable, domain.Health{Status: "degraded", Enabled: s.enabled, Store: "available"})
		return
	}
	writeJSON(w, http.StatusOK, domain.Health{
		Status:          "ok",
		Enabled:         s.enabled,
		Store:           "available",
		PendingCommands: metrics.PendingCommands,
		PendingEvents:   metrics.PendingEvents,
	})
}

func (s *Server) metrics(w http.ResponseWriter, r *http.Request) {
	metrics, err := s.store.Metrics(r.Context())
	if err != nil {
		writeError(w, http.StatusServiceUnavailable, "METRICS_UNAVAILABLE")
		return
	}
	enabled := 0
	if s.enabled {
		enabled = 1
	}
	w.Header().Set("Content-Type", "text/plain; version=0.0.4")
	w.WriteHeader(http.StatusOK)
	_, _ = fmt.Fprintf(w, "wazync_enabled %d\n", enabled)
	_, _ = fmt.Fprintf(w, "wazync_commands_pending %d\n", metrics.PendingCommands)
	_, _ = fmt.Fprintf(w, "wazync_events_pending %d\n", metrics.PendingEvents)
	_, _ = fmt.Fprintf(w, "wazync_events_failed %d\n", metrics.FailedEvents)
	_, _ = fmt.Fprintf(w, "wazync_sessions_active %d\n", metrics.ActiveSessions)
	_, _ = fmt.Fprintf(w, "wazync_leases_active %d\n", metrics.ActiveLeases)
	_, _ = fmt.Fprintf(w, "wazync_spool_files %d\n", metrics.SpoolFiles)
	if queryMetrics, ok := s.queries.(interface{ ProfilePictureQueriesInFlight() int64 }); ok {
		_, _ = fmt.Fprintf(
			w,
			"wazync_profile_picture_queries_in_flight %d\n",
			queryMetrics.ProfilePictureQueriesInFlight(),
		)
	}
	if s.scopeMetrics != nil {
		_, _ = fmt.Fprintf(
			w,
			"wazync_recipient_scope_rejections_total %d\n",
			s.scopeMetrics.RejectedScopeCount(),
		)
		if protocolMetrics, ok := s.scopeMetrics.(interface{ ProtocolControlRejectedCount() uint64 }); ok {
			_, _ = fmt.Fprintf(
				w,
				"wazync_protocol_control_rejections_total %d\n",
				protocolMetrics.ProtocolControlRejectedCount(),
			)
		}
	}
}

func (s *Server) securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		next.ServeHTTP(w, r)
	})
}

func readBody(reader io.Reader, maximum int64) ([]byte, error) {
	body, err := io.ReadAll(io.LimitReader(reader, maximum+1))
	if err != nil {
		return nil, err
	}
	if int64(len(body)) > maximum {
		return nil, errors.New("body exceeds configured limit")
	}
	return body, nil
}

func decodeStrict(body []byte, destination any) error {
	decoder := json.NewDecoder(strings.NewReader(string(body)))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return err
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return errors.New("unexpected content after JSON document")
	}
	return nil
}

func canonicalDigest(body []byte) (string, error) {
	var value any
	if err := json.Unmarshal(body, &value); err != nil {
		return "", err
	}
	canonical, err := json.Marshal(value)
	if err != nil {
		return "", err
	}
	digest := sha256.Sum256(canonical)
	return hex.EncodeToString(digest[:]), nil
}

func writeError(w http.ResponseWriter, status int, code string) {
	writeJSON(w, status, map[string]string{"error": code})
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
