package store

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Postgres struct {
	pool *pgxpool.Pool
	box  *cryptobox.Box
}

func OpenPostgres(ctx context.Context, databaseURL string, box *cryptobox.Box) (*Postgres, error) {
	pool, err := pgxpool.New(ctx, databaseURL)
	if err != nil {
		return nil, fmt.Errorf("open Wazync database: %w", err)
	}
	store := &Postgres{pool: pool, box: box}
	if err := store.Ping(ctx); err != nil {
		pool.Close()
		return nil, err
	}
	if err := store.Migrate(ctx); err != nil {
		pool.Close()
		return nil, err
	}
	return store, nil
}

const migrationSQL = `
CREATE TABLE IF NOT EXISTS wazync.commands (
    command_id varchar(128) PRIMARY KEY,
    session_id varchar(128) NOT NULL,
    command_type varchar(40) NOT NULL,
    provider_message_id varchar(128),
    payload_cipher bytea NOT NULL,
    payload_nonce bytea NOT NULL,
    payload_digest char(64) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'PENDING',
	attempt_count integer NOT NULL DEFAULT 0,
	available_at timestamptz NOT NULL DEFAULT now(),
	locked_at timestamptz,
    accepted_at timestamptz NOT NULL,
    processed_at timestamptz,
    error_code varchar(80),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS wazync_commands_pending_idx
    ON wazync.commands (status, accepted_at);
CREATE UNIQUE INDEX IF NOT EXISTS wazync_provider_message_uq
    ON wazync.commands (session_id, provider_message_id)
    WHERE provider_message_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS wazync.events (
    event_id varchar(128) PRIMARY KEY,
    session_id varchar(128) NOT NULL,
    event_type varchar(80) NOT NULL,
    occurred_at timestamptz NOT NULL,
    payload_cipher bytea NOT NULL,
    payload_nonce bytea NOT NULL,
    payload_digest char(64) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'PENDING',
    attempt_count integer NOT NULL DEFAULT 0,
    available_at timestamptz NOT NULL DEFAULT now(),
	locked_at timestamptz,
    delivered_at timestamptz,
    last_error_code varchar(80),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS wazync_events_delivery_idx
    ON wazync.events (status, available_at);

CREATE TABLE IF NOT EXISTS wazync.media_retry_states (
    session_id varchar(128) NOT NULL,
    provider_message_id varchar(128) NOT NULL,
    descriptor_cipher bytea NOT NULL,
    descriptor_nonce bytea NOT NULL,
    generation integer NOT NULL DEFAULT 0,
    attempt_count integer NOT NULL DEFAULT 0,
    expires_at timestamptz NOT NULL DEFAULT (now() + interval '7 days'),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (session_id, provider_message_id)
);
ALTER TABLE wazync.media_retry_states
    ADD COLUMN IF NOT EXISTS expires_at timestamptz NOT NULL DEFAULT (now() + interval '7 days');

CREATE TABLE IF NOT EXISTS wazync.nonces (
    nonce_digest char(64) PRIMARY KEY,
    expires_at timestamptz NOT NULL
);
CREATE INDEX IF NOT EXISTS wazync_nonces_expiry_idx
    ON wazync.nonces (expires_at);

CREATE TABLE IF NOT EXISTS wazync.sessions (
    session_id varchar(128) PRIMARY KEY,
    status varchar(32) NOT NULL DEFAULT 'DISCONNECTED',
    desired_connected boolean NOT NULL DEFAULT false,
    fencing_token bigint NOT NULL DEFAULT 0,
    reconnect_count integer NOT NULL DEFAULT 0,
    next_reconnect_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS wazync_sessions_claim_idx
    ON wazync.sessions (desired_connected, status, next_reconnect_at);

CREATE TABLE IF NOT EXISTS wazync.replicas (
    replica_id varchar(128) PRIMARY KEY,
    capacity integer NOT NULL,
    heartbeat_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS wazync.session_leases (
    session_id varchar(128) PRIMARY KEY REFERENCES wazync.sessions(session_id) ON DELETE CASCADE,
    replica_id varchar(128) NOT NULL,
    fencing_token bigint NOT NULL,
    expires_at timestamptz NOT NULL,
    renewed_at timestamptz NOT NULL,
    UNIQUE (session_id, fencing_token)
);
CREATE INDEX IF NOT EXISTS wazync_session_leases_owner_idx
    ON wazync.session_leases (replica_id, expires_at);

CREATE TABLE IF NOT EXISTS wazync.session_devices (
    session_id varchar(128) PRIMARY KEY REFERENCES wazync.sessions(session_id) ON DELETE CASCADE,
    device_jid_cipher bytea NOT NULL,
    device_jid_nonce bytea NOT NULL,
    device_jid_hash bytea,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE wazync.session_devices
    ADD COLUMN IF NOT EXISTS device_jid_hash bytea;
CREATE UNIQUE INDEX IF NOT EXISTS wazync_session_devices_jid_hash_uq
    ON wazync.session_devices (device_jid_hash)
    WHERE device_jid_hash IS NOT NULL;

ALTER TABLE wazync.sessions
    ALTER COLUMN status SET DEFAULT 'DISCONNECTED';
UPDATE wazync.sessions
SET status = CASE
    WHEN status = 'CONNECTED' THEN 'CONNECTED'
    WHEN status IN ('CONNECTING', 'PAIRING') THEN 'CONNECTING'
    ELSE 'DISCONNECTED'
END
WHERE status NOT IN ('DISCONNECTED', 'CONNECTING', 'CONNECTED');
`

func (s *Postgres) Migrate(ctx context.Context) error {
	if _, err := s.pool.Exec(ctx, migrationSQL); err != nil {
		return fmt.Errorf("migrate Wazync database: %w", err)
	}
	return nil
}

func (s *Postgres) PutMediaRetryState(ctx context.Context, state domain.MediaRetryState) error {
	if state.SessionID == "" || state.MessageID == "" || len(state.Descriptor) == 0 {
		return errors.New("invalid media retry state")
	}
	aad := []byte(state.SessionID + "\x00" + state.MessageID)
	ciphertext, nonce, err := s.box.Seal(state.Descriptor, aad)
	if err != nil {
		return fmt.Errorf("encrypt media retry descriptor: %w", err)
	}
	_, err = s.pool.Exec(ctx, `
INSERT INTO wazync.media_retry_states (
    session_id, provider_message_id, descriptor_cipher, descriptor_nonce, expires_at, updated_at
) VALUES ($1, $2, $3, $4, $5, now())
ON CONFLICT (session_id, provider_message_id) DO UPDATE SET
    descriptor_cipher = EXCLUDED.descriptor_cipher,
    descriptor_nonce = EXCLUDED.descriptor_nonce,
    expires_at = EXCLUDED.expires_at,
    updated_at = now()`,
		state.SessionID, state.MessageID, ciphertext, nonce, state.ExpiresAt,
	)
	if err != nil {
		return fmt.Errorf("persist media retry state: %w", err)
	}
	return nil
}

func (s *Postgres) GetMediaRetryState(
	ctx context.Context,
	sessionID, messageID string,
) (domain.MediaRetryState, error) {
	var state domain.MediaRetryState
	var ciphertext, nonce []byte
	err := s.pool.QueryRow(ctx, `
SELECT session_id, provider_message_id, descriptor_cipher, descriptor_nonce,
       generation, attempt_count, updated_at, expires_at
FROM wazync.media_retry_states
WHERE session_id = $1 AND provider_message_id = $2 AND expires_at > now()`,
		sessionID, messageID,
	).Scan(
		&state.SessionID, &state.MessageID, &ciphertext, &nonce,
		&state.Generation, &state.Attempts, &state.UpdatedAt, &state.ExpiresAt,
	)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.MediaRetryState{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.MediaRetryState{}, fmt.Errorf("get media retry state: %w", err)
	}
	state.Descriptor, err = s.box.Open(ciphertext, nonce, []byte(sessionID+"\x00"+messageID))
	if err != nil {
		return domain.MediaRetryState{}, fmt.Errorf("decrypt media retry descriptor: %w", err)
	}
	return state, nil
}

func (s *Postgres) BeginMediaRetry(
	ctx context.Context,
	sessionID, messageID string,
	now time.Time,
) (domain.MediaRetryState, error) {
	var state domain.MediaRetryState
	var ciphertext, nonce []byte
	err := s.pool.QueryRow(ctx, `
UPDATE wazync.media_retry_states
SET generation = generation + 1, attempt_count = attempt_count + 1, updated_at = $3
WHERE session_id = $1 AND provider_message_id = $2 AND expires_at > $3
RETURNING session_id, provider_message_id, descriptor_cipher, descriptor_nonce,
          generation, attempt_count, updated_at, expires_at`, sessionID, messageID, now,
	).Scan(
		&state.SessionID, &state.MessageID, &ciphertext, &nonce,
		&state.Generation, &state.Attempts, &state.UpdatedAt, &state.ExpiresAt,
	)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.MediaRetryState{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.MediaRetryState{}, fmt.Errorf("begin media retry: %w", err)
	}
	state.Descriptor, err = s.box.Open(ciphertext, nonce, []byte(sessionID+"\x00"+messageID))
	if err != nil {
		return domain.MediaRetryState{}, fmt.Errorf("decrypt media retry descriptor: %w", err)
	}
	return state, nil
}

func (s *Postgres) DeleteMediaRetryState(ctx context.Context, sessionID, messageID string) error {
	_, err := s.pool.Exec(ctx, `
DELETE FROM wazync.media_retry_states
WHERE session_id = $1 AND provider_message_id = $2`, sessionID, messageID)
	if err != nil {
		return fmt.Errorf("delete media retry state: %w", err)
	}
	return nil
}

func (s *Postgres) AcceptCommand(ctx context.Context, command domain.Command) (bool, error) {
	ciphertext, nonce, err := s.box.Seal(command.Payload, []byte(command.CommandID))
	if err != nil {
		return false, fmt.Errorf("encrypt command payload: %w", err)
	}
	result, err := s.pool.Exec(ctx, `
INSERT INTO wazync.commands (
    command_id, session_id, command_type, provider_message_id,
    payload_cipher, payload_nonce, payload_digest, accepted_at
) VALUES ($1, $2, $3, NULLIF($4, ''), $5, $6, $7, $8)
ON CONFLICT (command_id) DO NOTHING`,
		command.CommandID, command.SessionID, command.Type, command.ProviderMessageID,
		ciphertext, nonce, command.Digest, command.AcceptedAt,
	)
	if err != nil {
		return false, fmt.Errorf("persist command: %w", err)
	}
	if result.RowsAffected() == 1 {
		return false, nil
	}

	var digest string
	if err := s.pool.QueryRow(ctx,
		`SELECT payload_digest FROM wazync.commands WHERE command_id = $1`, command.CommandID,
	).Scan(&digest); err != nil {
		return false, fmt.Errorf("read existing command: %w", err)
	}
	if digest != command.Digest {
		return false, domain.ErrDigestConflict
	}
	return true, nil
}

func (s *Postgres) NextCommands(
	ctx context.Context,
	replicaID string,
	limit int,
	now time.Time,
) ([]domain.PendingCommand, error) {
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return nil, fmt.Errorf("begin command processing: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	rows, err := tx.Query(ctx, `
SELECT c.command_id, c.session_id, c.command_type, COALESCE(c.provider_message_id, ''),
       c.payload_cipher, c.payload_nonce, c.payload_digest, c.accepted_at, c.attempt_count
FROM wazync.commands c
WHERE (
      c.status IN ('PENDING', 'RETRY') AND c.available_at <= $1
   OR c.status = 'PROCESSING' AND c.locked_at <= $1 - interval '2 minutes'
)
AND (
    c.command_type = 'SESSION_PROVISION'
    OR (
        c.command_type IN ('SESSION_PAIR', 'SESSION_CONNECT', 'SESSION_DISCONNECT', 'SESSION_RESET', 'SESSION_LOGOUT')
        AND (
            NOT EXISTS (
                SELECT 1 FROM wazync.session_leases absent
                WHERE absent.session_id = c.session_id AND absent.expires_at > $1
            )
            OR EXISTS (
                SELECT 1 FROM wazync.session_leases owned
                WHERE owned.session_id = c.session_id AND owned.replica_id = $2 AND owned.expires_at > $1
            )
        )
    )
    OR EXISTS (
        SELECT 1 FROM wazync.session_leases l
        WHERE l.session_id = c.session_id AND l.replica_id = $2 AND l.expires_at > $1
    )
)
ORDER BY c.accepted_at, c.command_id
FOR UPDATE OF c SKIP LOCKED
LIMIT $3`, now, replicaID, limit)
	if err != nil {
		return nil, fmt.Errorf("select commands: %w", err)
	}

	type lockedCommand struct {
		command    domain.Command
		ciphertext []byte
		nonce      []byte
		attempts   int
	}
	selected := make([]lockedCommand, 0, limit)
	for rows.Next() {
		var item lockedCommand
		if err := rows.Scan(
			&item.command.CommandID, &item.command.SessionID, &item.command.Type, &item.command.ProviderMessageID,
			&item.ciphertext, &item.nonce, &item.command.Digest, &item.command.AcceptedAt, &item.attempts,
		); err != nil {
			rows.Close()
			return nil, fmt.Errorf("scan command: %w", err)
		}
		selected = append(selected, item)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	commands := make([]domain.PendingCommand, 0, len(selected))
	for _, item := range selected {
		payload, err := s.box.Open(item.ciphertext, item.nonce, []byte(item.command.CommandID))
		if err != nil {
			return nil, fmt.Errorf("decrypt command payload: %w", err)
		}
		item.command.ContractVersion = "v1"
		item.command.Payload = payload
		attempts := item.attempts + 1
		if _, err := tx.Exec(ctx, `
UPDATE wazync.commands
SET status = 'PROCESSING', attempt_count = $2, locked_at = $3, updated_at = $3
WHERE command_id = $1`, item.command.CommandID, attempts, now); err != nil {
			return nil, fmt.Errorf("lock command: %w", err)
		}
		commands = append(commands, domain.PendingCommand{Command: item.command, Attempts: attempts})
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, fmt.Errorf("commit command locks: %w", err)
	}
	return commands, nil
}

func (s *Postgres) MarkCommandProcessed(ctx context.Context, commandID string, processedAt time.Time) error {
	result, err := s.pool.Exec(ctx, `
UPDATE wazync.commands
SET status = 'PROCESSED', processed_at = $2, locked_at = NULL, updated_at = $2
WHERE command_id = $1`, commandID, processedAt)
	if err != nil {
		return fmt.Errorf("mark command processed: %w", err)
	}
	if result.RowsAffected() != 1 {
		return domain.ErrNotFound
	}
	return nil
}

func (s *Postgres) MarkCommandFailed(
	ctx context.Context,
	commandID string,
	availableAt time.Time,
	errorCode string,
	terminal bool,
) error {
	status := "RETRY"
	if terminal {
		status = "ERROR"
	}
	result, err := s.pool.Exec(ctx, `
UPDATE wazync.commands
SET status = $2, available_at = $3, error_code = $4, locked_at = NULL, updated_at = now()
WHERE command_id = $1`, commandID, status, availableAt, errorCode)
	if err != nil {
		return fmt.Errorf("mark command failed: %w", err)
	}
	if result.RowsAffected() != 1 {
		return domain.ErrNotFound
	}
	return nil
}

func (s *Postgres) AppendEvent(ctx context.Context, event domain.Event) (bool, error) {
	ciphertext, nonce, err := s.box.Seal(event.Payload, []byte(event.EventID))
	if err != nil {
		return false, fmt.Errorf("encrypt event payload: %w", err)
	}
	result, err := s.pool.Exec(ctx, `
INSERT INTO wazync.events (
    event_id, session_id, event_type, occurred_at,
    payload_cipher, payload_nonce, payload_digest
) VALUES ($1, $2, $3, $4, $5, $6, $7)
ON CONFLICT (event_id) DO NOTHING`,
		event.EventID, event.SessionID, event.Type, event.OccurredAt,
		ciphertext, nonce, event.Digest,
	)
	if err != nil {
		return false, fmt.Errorf("persist event: %w", err)
	}
	if result.RowsAffected() == 1 {
		return false, nil
	}

	var digest string
	if err := s.pool.QueryRow(ctx,
		`SELECT payload_digest FROM wazync.events WHERE event_id = $1`, event.EventID,
	).Scan(&digest); err != nil {
		return false, fmt.Errorf("read existing event: %w", err)
	}
	if digest != event.Digest {
		return false, domain.ErrDigestConflict
	}
	return true, nil
}

func (s *Postgres) NextEvents(ctx context.Context, limit int, now time.Time) ([]domain.PendingEvent, error) {
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return nil, fmt.Errorf("begin event delivery: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	rows, err := tx.Query(ctx, `
SELECT event_id, session_id, event_type, occurred_at,
       payload_cipher, payload_nonce, payload_digest, attempt_count
FROM wazync.events
WHERE (
      status IN ('PENDING', 'RETRY', 'ERROR') AND available_at <= $1
   OR status = 'DISPATCHING' AND locked_at <= $1 - interval '2 minutes'
)
ORDER BY available_at, event_id
FOR UPDATE SKIP LOCKED
LIMIT $2`, now, limit)
	if err != nil {
		return nil, fmt.Errorf("select pending events: %w", err)
	}

	type lockedEvent struct {
		event      domain.Event
		ciphertext []byte
		nonce      []byte
		attempts   int
	}
	selected := make([]lockedEvent, 0, limit)
	for rows.Next() {
		var item lockedEvent
		if err := rows.Scan(
			&item.event.EventID, &item.event.SessionID, &item.event.Type, &item.event.OccurredAt,
			&item.ciphertext, &item.nonce, &item.event.Digest, &item.attempts,
		); err != nil {
			rows.Close()
			return nil, fmt.Errorf("scan pending event: %w", err)
		}
		selected = append(selected, item)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("iterate pending events: %w", err)
	}

	pending := make([]domain.PendingEvent, 0, len(selected))
	for _, item := range selected {
		payload, err := s.box.Open(item.ciphertext, item.nonce, []byte(item.event.EventID))
		if err != nil {
			return nil, fmt.Errorf("decrypt pending event: %w", err)
		}
		item.event.ContractVersion = "v1"
		item.event.Payload = payload
		attempts := item.attempts + 1
		if _, err := tx.Exec(ctx, `
UPDATE wazync.events
SET status = 'DISPATCHING', attempt_count = $2, locked_at = $3, updated_at = $3
WHERE event_id = $1`, item.event.EventID, attempts, now); err != nil {
			return nil, fmt.Errorf("lock pending event: %w", err)
		}
		pending = append(pending, domain.PendingEvent{Event: item.event, Attempts: attempts})
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, fmt.Errorf("commit event delivery locks: %w", err)
	}
	return pending, nil
}

func (s *Postgres) MarkEventDelivered(ctx context.Context, eventID string, deliveredAt time.Time) error {
	result, err := s.pool.Exec(ctx, `
UPDATE wazync.events
SET status = 'DELIVERED', delivered_at = $2, locked_at = NULL, updated_at = $2
WHERE event_id = $1`, eventID, deliveredAt)
	if err != nil {
		return fmt.Errorf("mark event delivered: %w", err)
	}
	if result.RowsAffected() != 1 {
		return domain.ErrNotFound
	}
	return nil
}

func (s *Postgres) MarkEventFailed(
	ctx context.Context,
	eventID string,
	availableAt time.Time,
	errorCode string,
	terminal bool,
) error {
	status := "RETRY"
	if terminal {
		status = "ERROR"
	}
	result, err := s.pool.Exec(ctx, `
UPDATE wazync.events
SET status = $2, available_at = $3, last_error_code = $4,
    locked_at = NULL, updated_at = now()
WHERE event_id = $1`, eventID, status, availableAt, errorCode)
	if err != nil {
		return fmt.Errorf("mark event failed: %w", err)
	}
	if result.RowsAffected() != 1 {
		return domain.ErrNotFound
	}
	return nil
}

func (s *Postgres) ClaimNonce(ctx context.Context, digest string, expiresAt time.Time) (bool, error) {
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return false, fmt.Errorf("begin nonce claim: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	if _, err := tx.Exec(ctx, `DELETE FROM wazync.nonces WHERE expires_at <= now()`); err != nil {
		return false, fmt.Errorf("expire nonces: %w", err)
	}
	result, err := tx.Exec(ctx, `
INSERT INTO wazync.nonces (nonce_digest, expires_at)
VALUES ($1, $2)
ON CONFLICT (nonce_digest) DO NOTHING`, digest, expiresAt)
	if err != nil {
		return false, fmt.Errorf("claim nonce: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return false, fmt.Errorf("commit nonce claim: %w", err)
	}
	return result.RowsAffected() == 1, nil
}

func (s *Postgres) UpsertSession(ctx context.Context, session domain.Session) error {
	if !session.Valid() {
		return errors.New("invalid session")
	}
	var nextReconnectAt any
	if !session.NextReconnectAt.IsZero() {
		nextReconnectAt = session.NextReconnectAt
	}
	_, err := s.pool.Exec(ctx, `
INSERT INTO wazync.sessions (
    session_id, status, desired_connected, reconnect_count, next_reconnect_at, updated_at
) VALUES ($1, $2, $3, $4, $5, now())
ON CONFLICT (session_id) DO UPDATE SET
    status = EXCLUDED.status,
    desired_connected = EXCLUDED.desired_connected,
    reconnect_count = EXCLUDED.reconnect_count,
    next_reconnect_at = EXCLUDED.next_reconnect_at,
    updated_at = now()`,
		session.SessionID, session.Status, session.DesiredConnected,
		session.ReconnectCount, nextReconnectAt,
	)
	if err != nil {
		return fmt.Errorf("upsert session: %w", err)
	}
	return nil
}

func (s *Postgres) GetSession(ctx context.Context, sessionID string) (domain.Session, error) {
	var session domain.Session
	var nextReconnectAt *time.Time
	err := s.pool.QueryRow(ctx, `
SELECT session_id, status, desired_connected, fencing_token,
       reconnect_count, next_reconnect_at, updated_at
FROM wazync.sessions
WHERE session_id = $1`, sessionID).Scan(
		&session.SessionID, &session.Status, &session.DesiredConnected, &session.FencingToken,
		&session.ReconnectCount, &nextReconnectAt, &session.UpdatedAt,
	)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.Session{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.Session{}, fmt.Errorf("get session: %w", err)
	}
	if nextReconnectAt != nil {
		session.NextReconnectAt = *nextReconnectAt
	}
	return session, nil
}

func (s *Postgres) SetSessionStatus(
	ctx context.Context,
	sessionID string,
	status domain.SessionStatus,
	reconnectCount int,
	nextReconnectAt time.Time,
) error {
	var next any
	if !nextReconnectAt.IsZero() {
		next = nextReconnectAt
	}
	result, err := s.pool.Exec(ctx, `
UPDATE wazync.sessions
SET status = $2, reconnect_count = $3, next_reconnect_at = $4, updated_at = now()
WHERE session_id = $1`, sessionID, status, reconnectCount, next)
	if err != nil {
		return fmt.Errorf("set session status: %w", err)
	}
	if result.RowsAffected() != 1 {
		return domain.ErrNotFound
	}
	return nil
}

func (s *Postgres) ClaimSessions(
	ctx context.Context,
	replicaID string,
	capacity int,
	now time.Time,
	ttl time.Duration,
) ([]domain.Lease, error) {
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return nil, fmt.Errorf("begin session claim: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	if _, err := tx.Exec(ctx, `
INSERT INTO wazync.replicas (replica_id, capacity, heartbeat_at, updated_at)
VALUES ($1, $2, $3, $3)
ON CONFLICT (replica_id) DO UPDATE SET
    capacity = EXCLUDED.capacity, heartbeat_at = EXCLUDED.heartbeat_at, updated_at = EXCLUDED.updated_at`,
		replicaID, capacity, now); err != nil {
		return nil, fmt.Errorf("heartbeat replica: %w", err)
	}

	var owned int
	if err := tx.QueryRow(ctx, `
SELECT count(*) FROM wazync.session_leases
WHERE replica_id = $1 AND expires_at > $2`, replicaID, now).Scan(&owned); err != nil {
		return nil, fmt.Errorf("count replica leases: %w", err)
	}
	remaining := capacity - owned
	if remaining <= 0 {
		if err := tx.Commit(ctx); err != nil {
			return nil, err
		}
		return nil, nil
	}

	rows, err := tx.Query(ctx, `
SELECT s.session_id
FROM wazync.sessions s
LEFT JOIN wazync.session_leases l ON l.session_id = s.session_id
WHERE s.desired_connected = true
  AND (s.next_reconnect_at IS NULL OR s.next_reconnect_at <= $1)
  AND (l.session_id IS NULL OR l.expires_at <= $1)
ORDER BY s.session_id
FOR UPDATE OF s SKIP LOCKED
LIMIT $2`, now, remaining)
	if err != nil {
		return nil, fmt.Errorf("select claimable sessions: %w", err)
	}
	var ids []string
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, err
		}
		ids = append(ids, id)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	leases := make([]domain.Lease, 0, len(ids))
	for _, sessionID := range ids {
		var token int64
		if err := tx.QueryRow(ctx, `
UPDATE wazync.sessions
SET fencing_token = fencing_token + 1, updated_at = $2
WHERE session_id = $1
RETURNING fencing_token`, sessionID, now).Scan(&token); err != nil {
			return nil, fmt.Errorf("advance fencing token: %w", err)
		}
		lease := domain.Lease{
			SessionID: sessionID, ReplicaID: replicaID, FencingToken: token, ExpiresAt: now.Add(ttl),
		}
		if _, err := tx.Exec(ctx, `
INSERT INTO wazync.session_leases (
    session_id, replica_id, fencing_token, expires_at, renewed_at
) VALUES ($1, $2, $3, $4, $5)
ON CONFLICT (session_id) DO UPDATE SET
    replica_id = EXCLUDED.replica_id,
    fencing_token = EXCLUDED.fencing_token,
    expires_at = EXCLUDED.expires_at,
    renewed_at = EXCLUDED.renewed_at`,
			lease.SessionID, lease.ReplicaID, lease.FencingToken, lease.ExpiresAt, now); err != nil {
			return nil, fmt.Errorf("persist session lease: %w", err)
		}
		leases = append(leases, lease)
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, fmt.Errorf("commit session claims: %w", err)
	}
	return leases, nil
}

func (s *Postgres) ClaimSession(
	ctx context.Context,
	sessionID, replicaID string,
	capacity int,
	now time.Time,
	ttl time.Duration,
) (domain.Lease, bool, error) {
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return domain.Lease{}, false, fmt.Errorf("begin exact session claim: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()
	if _, err := tx.Exec(ctx, `
INSERT INTO wazync.replicas (replica_id, capacity, heartbeat_at, updated_at)
VALUES ($1, $2, $3, $3)
ON CONFLICT (replica_id) DO UPDATE SET
    capacity = EXCLUDED.capacity, heartbeat_at = EXCLUDED.heartbeat_at, updated_at = EXCLUDED.updated_at`,
		replicaID, capacity, now); err != nil {
		return domain.Lease{}, false, fmt.Errorf("heartbeat exact-claim replica: %w", err)
	}
	var fencingToken int64
	if err := tx.QueryRow(ctx, `
SELECT fencing_token
FROM wazync.sessions
WHERE session_id = $1
FOR UPDATE`, sessionID).Scan(&fencingToken); errors.Is(err, pgx.ErrNoRows) {
		return domain.Lease{}, false, domain.ErrNotFound
	} else if err != nil {
		return domain.Lease{}, false, fmt.Errorf("lock exact-claim session: %w", err)
	}
	var current domain.Lease
	err = tx.QueryRow(ctx, `
SELECT session_id, replica_id, fencing_token, expires_at
FROM wazync.session_leases
WHERE session_id = $1`, sessionID).Scan(
		&current.SessionID, &current.ReplicaID, &current.FencingToken, &current.ExpiresAt,
	)
	if err == nil && current.ExpiresAt.After(now) {
		if err := tx.Commit(ctx); err != nil {
			return domain.Lease{}, false, fmt.Errorf("commit existing exact claim: %w", err)
		}
		return current, current.ReplicaID == replicaID, nil
	}
	if err != nil && !errors.Is(err, pgx.ErrNoRows) {
		return domain.Lease{}, false, fmt.Errorf("read exact-claim lease: %w", err)
	}
	var owned int
	if err := tx.QueryRow(ctx, `
SELECT count(*) FROM wazync.session_leases
WHERE replica_id = $1 AND expires_at > $2`, replicaID, now).Scan(&owned); err != nil {
		return domain.Lease{}, false, fmt.Errorf("count exact-claim leases: %w", err)
	}
	if owned >= capacity {
		if err := tx.Commit(ctx); err != nil {
			return domain.Lease{}, false, err
		}
		return domain.Lease{}, false, nil
	}
	fencingToken++
	lease := domain.Lease{
		SessionID: sessionID, ReplicaID: replicaID,
		FencingToken: fencingToken, ExpiresAt: now.Add(ttl),
	}
	if _, err := tx.Exec(ctx, `
UPDATE wazync.sessions
SET fencing_token = $2, updated_at = $3
WHERE session_id = $1`, sessionID, fencingToken, now); err != nil {
		return domain.Lease{}, false, fmt.Errorf("advance exact-claim fencing token: %w", err)
	}
	if _, err := tx.Exec(ctx, `
INSERT INTO wazync.session_leases (
    session_id, replica_id, fencing_token, expires_at, renewed_at
) VALUES ($1, $2, $3, $4, $5)
ON CONFLICT (session_id) DO UPDATE SET
    replica_id = EXCLUDED.replica_id,
    fencing_token = EXCLUDED.fencing_token,
    expires_at = EXCLUDED.expires_at,
    renewed_at = EXCLUDED.renewed_at`,
		lease.SessionID, lease.ReplicaID, lease.FencingToken, lease.ExpiresAt, now); err != nil {
		return domain.Lease{}, false, fmt.Errorf("persist exact session lease: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return domain.Lease{}, false, fmt.Errorf("commit exact session claim: %w", err)
	}
	return lease, true, nil
}

func (s *Postgres) ListReplicaLeases(ctx context.Context, replicaID string, now time.Time) ([]domain.Lease, error) {
	rows, err := s.pool.Query(ctx, `
SELECT session_id, replica_id, fencing_token, expires_at
FROM wazync.session_leases
WHERE replica_id = $1 AND expires_at > $2
ORDER BY session_id`, replicaID, now)
	if err != nil {
		return nil, fmt.Errorf("list replica leases: %w", err)
	}
	defer rows.Close()
	leases := make([]domain.Lease, 0)
	for rows.Next() {
		var lease domain.Lease
		if err := rows.Scan(&lease.SessionID, &lease.ReplicaID, &lease.FencingToken, &lease.ExpiresAt); err != nil {
			return nil, fmt.Errorf("scan replica lease: %w", err)
		}
		leases = append(leases, lease)
	}
	return leases, rows.Err()
}

func (s *Postgres) RenewLease(
	ctx context.Context,
	lease domain.Lease,
	now time.Time,
	ttl time.Duration,
) (domain.Lease, error) {
	err := s.pool.QueryRow(ctx, `
UPDATE wazync.session_leases
SET expires_at = $4, renewed_at = $3
WHERE session_id = $1 AND replica_id = $2 AND fencing_token = $5 AND expires_at > $3
RETURNING expires_at`,
		lease.SessionID, lease.ReplicaID, now, now.Add(ttl), lease.FencingToken,
	).Scan(&lease.ExpiresAt)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.Lease{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.Lease{}, fmt.Errorf("renew session lease: %w", err)
	}
	return lease, nil
}

func (s *Postgres) ValidLease(ctx context.Context, lease domain.Lease, now time.Time) (bool, error) {
	var valid bool
	err := s.pool.QueryRow(ctx, `
SELECT EXISTS (
    SELECT 1 FROM wazync.session_leases
    WHERE session_id = $1 AND replica_id = $2 AND fencing_token = $3 AND expires_at > $4
)`, lease.SessionID, lease.ReplicaID, lease.FencingToken, now).Scan(&valid)
	return valid, err
}

func (s *Postgres) ReleaseLease(ctx context.Context, lease domain.Lease) error {
	_, err := s.pool.Exec(ctx, `
DELETE FROM wazync.session_leases
WHERE session_id = $1 AND replica_id = $2 AND fencing_token = $3`,
		lease.SessionID, lease.ReplicaID, lease.FencingToken)
	if err != nil {
		return fmt.Errorf("release session lease: %w", err)
	}
	return nil
}

func (s *Postgres) Ping(ctx context.Context) error {
	if err := s.pool.Ping(ctx); err != nil {
		return fmt.Errorf("Wazync database unavailable: %w", err)
	}
	return nil
}

func (s *Postgres) Metrics(ctx context.Context) (domain.Metrics, error) {
	var metrics domain.Metrics
	err := s.pool.QueryRow(ctx, `
SELECT
    (SELECT count(*) FROM wazync.commands WHERE status IN ('PENDING', 'PROCESSING')),
    (SELECT count(*) FROM wazync.events WHERE status IN ('PENDING', 'RETRY')),
    (SELECT count(*) FROM wazync.events WHERE status = 'ERROR'),
    COALESCE((SELECT count(*) FROM wazync.sessions WHERE status = 'CONNECTED'), 0),
    COALESCE((SELECT count(*) FROM wazync.session_leases WHERE expires_at > now()), 0)
`).Scan(
		&metrics.PendingCommands,
		&metrics.PendingEvents,
		&metrics.FailedEvents,
		&metrics.ActiveSessions,
		&metrics.ActiveLeases,
	)
	if err != nil && !errors.Is(err, pgx.ErrNoRows) {
		// During the first migration wave session tables may not exist yet.
		err = s.pool.QueryRow(ctx, `
SELECT
    (SELECT count(*) FROM wazync.commands WHERE status IN ('PENDING', 'PROCESSING')),
    (SELECT count(*) FROM wazync.events WHERE status IN ('PENDING', 'RETRY')),
    (SELECT count(*) FROM wazync.events WHERE status = 'ERROR')
`).Scan(&metrics.PendingCommands, &metrics.PendingEvents, &metrics.FailedEvents)
	}
	if err != nil {
		return domain.Metrics{}, fmt.Errorf("collect Wazync metrics: %w", err)
	}
	return metrics, nil
}

func (s *Postgres) Close() { s.pool.Close() }
