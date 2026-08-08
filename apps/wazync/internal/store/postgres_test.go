package store

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
)

type recordingCommandFinalizer struct {
	tag   pgconn.CommandTag
	query string
	args  []any
}

func (f *recordingCommandFinalizer) Exec(_ context.Context, query string, args ...any) (pgconn.CommandTag, error) {
	f.query = query
	f.args = args

	return f.tag, nil
}

type recordingEventExecutor struct {
	tag    pgconn.CommandTag
	query  string
	args   []any
	digest string
	rowErr error
}

func (e *recordingEventExecutor) Exec(_ context.Context, query string, args ...any) (pgconn.CommandTag, error) {
	e.query = query
	e.args = args
	return e.tag, nil
}

func (e *recordingEventExecutor) QueryRow(_ context.Context, query string, args ...any) pgx.Row {
	e.query = query
	e.args = args
	return recordingEventRow{digest: e.digest, err: e.rowErr}
}

type recordingEventRow struct {
	digest string
	err    error
}

func (r recordingEventRow) Scan(dest ...any) error {
	if r.err != nil {
		return r.err
	}
	if len(dest) != 1 {
		return errors.New("unexpected scan destination count")
	}
	target, ok := dest[0].(*string)
	if !ok {
		return errors.New("unexpected scan destination type")
	}
	*target = r.digest
	return nil
}

func TestMigrationUsesOnlyWazyncSchema(t *testing.T) {
	t.Parallel()

	if strings.Contains(strings.ToUpper(migrationSQL), "CREATE SCHEMA") {
		t.Fatal("runtime migration must not create schemas")
	}
	if !strings.Contains(migrationSQL, "CREATE TABLE IF NOT EXISTS wazync.commands") {
		t.Fatal("migration does not qualify tables with the Wazync schema")
	}
	removedSchema := "whatsapp_" + "gateway"
	if strings.Contains(migrationSQL, removedSchema) {
		t.Fatalf("migration references removed schema %q", removedSchema)
	}
}

func TestPgxUsesEnvironmentPasswordWhenURIHasNone(t *testing.T) {
	reservedPassword := "ci:@/?#%local-password"
	t.Setenv("PGPASSWORD", reservedPassword)

	config, err := pgxpool.ParseConfig(
		"postgres://wazync@postgres:5432/nfse?search_path=wazync",
	)
	if err != nil {
		t.Fatalf("parse PostgreSQL config: %v", err)
	}
	if config.ConnConfig.Password != reservedPassword {
		t.Fatal("pgx did not source the password from PGPASSWORD")
	}
}

func TestPostgresMediaRetryFinalizationUsesCanonicalCommandType(t *testing.T) {
	t.Parallel()

	now := time.Now().UTC()
	tests := []struct {
		name       string
		wantStatus string
		wantArgs   []any
		finalize   func(commandFinalizer) error
	}{
		{
			name:       "processed",
			wantStatus: "SET status = 'PROCESSED'",
			wantArgs:   []any{"command-media-retry-processed", 2, now, string(domain.CommandRetryMedia)},
			finalize: func(executor commandFinalizer) error {
				return finalizeMediaRetryCommandProcessed(t.Context(), executor, "command-media-retry-processed", 2, now)
			},
		},
		{
			name:       "retry",
			wantStatus: "SET status = 'RETRY'",
			wantArgs:   []any{"command-media-retry-failed", 3, now, "MEDIA_DOWNLOAD_FAILED", string(domain.CommandRetryMedia)},
			finalize: func(executor commandFinalizer) error {
				return finalizeMediaRetryCommandFailed(t.Context(), executor, "command-media-retry-failed", 3, now, "MEDIA_DOWNLOAD_FAILED")
			},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			recorder := &recordingCommandFinalizer{tag: pgconn.NewCommandTag("UPDATE 1")}
			if err := test.finalize(recorder); err != nil {
				t.Fatalf("finalize media retry: %v", err)
			}
			if !strings.Contains(recorder.query, test.wantStatus) {
				t.Fatalf("finalizer does not apply %q: %s", test.wantStatus, recorder.query)
			}
			if !strings.Contains(recorder.query, "status = 'PROCESSING' AND attempt_count = $2") {
				t.Fatalf("finalizer lost attempt fencing: %s", recorder.query)
			}
			if strings.Contains(recorder.query, "command_type = '") {
				t.Fatalf("finalizer duplicates a command type literal: %s", recorder.query)
			}
			if !reflect.DeepEqual(recorder.args, test.wantArgs) {
				t.Fatalf("finalizer arguments = %#v, want %#v", recorder.args, test.wantArgs)
			}
		})
	}
}

func TestPostgresMediaRetryFinalizationRejectsStaleAttempt(t *testing.T) {
	t.Parallel()

	now := time.Now().UTC()
	for _, finalize := range []func(commandFinalizer) error{
		func(executor commandFinalizer) error {
			return finalizeMediaRetryCommandProcessed(t.Context(), executor, "command-media-retry-stale", 1, now)
		},
		func(executor commandFinalizer) error {
			return finalizeMediaRetryCommandFailed(t.Context(), executor, "command-media-retry-stale", 1, now, "MEDIA_DOWNLOAD_FAILED")
		},
	} {
		recorder := &recordingCommandFinalizer{tag: pgconn.NewCommandTag("UPDATE 0")}
		if err := finalize(recorder); !errors.Is(err, domain.ErrStateConflict) {
			t.Fatalf("stale finalization error = %v, want state conflict", err)
		}
	}
}

func TestPostgresProcessedEventConflictReadUsesEventID(t *testing.T) {
	t.Parallel()
	executor := &recordingEventExecutor{
		tag:    pgconn.NewCommandTag("INSERT 0"),
		digest: "event-digest",
	}
	event := domain.Event{EventID: "event-processed-conflict-read", Digest: "event-digest"}
	if err := insertEventIfAbsent(t.Context(), executor, event, []byte("cipher"), []byte("nonce")); err != nil {
		t.Fatalf("insert processed event: %v", err)
	}
	if len(executor.args) != 1 || executor.args[0] != event.EventID {
		t.Fatalf("event conflict read args = %#v, want %q", executor.args, event.EventID)
	}
	if !strings.Contains(executor.query, "SELECT payload_digest FROM wazync.events WHERE event_id = $1") {
		t.Fatalf("event conflict read query = %q", executor.query)
	}
}

func TestPostgresProcessedEventConflictReadRejectsDifferentDigest(t *testing.T) {
	t.Parallel()
	executor := &recordingEventExecutor{
		tag:    pgconn.NewCommandTag("INSERT 0"),
		digest: "stored-digest",
	}
	event := domain.Event{EventID: "event-processed-conflict-digest", Digest: "incoming-digest"}
	if err := insertEventIfAbsent(t.Context(), executor, event, []byte("cipher"), []byte("nonce")); !errors.Is(err, domain.ErrDigestConflict) {
		t.Fatalf("conflicting event digest error = %v, want digest conflict", err)
	}
}
