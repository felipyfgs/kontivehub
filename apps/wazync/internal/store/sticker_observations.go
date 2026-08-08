package store

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/jackc/pgx/v5"
)

const stickerObservationDefaultTTL = 30 * time.Minute

func (s *Memory) PutStickerObservation(_ context.Context, state domain.StickerObservationState) error {
	if err := validateStickerObservation(state); err != nil {
		return err
	}
	if state.ExpiresAt.IsZero() {
		state.ExpiresAt = time.Now().UTC().Add(stickerObservationDefaultTTL)
	}
	state.Descriptor = append([]byte(nil), state.Descriptor...)
	state.CorrelationAliases = append([]string(nil), state.CorrelationAliases...)

	s.mu.Lock()
	defer s.mu.Unlock()
	key := stickerObservationKey(state.SessionID, state.ObservationID)
	if current, exists := s.stickerObservations[key]; exists && len(state.Descriptor) == 0 {
		state.Descriptor = append([]byte(nil), current.Descriptor...)
	}
	s.stickerObservations[key] = state
	return nil
}

func (s *Memory) ResolveStickerObservation(
	_ context.Context,
	sessionID string,
	observationID string,
	now time.Time,
) (domain.StickerObservationState, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	targetKey := stickerObservationKey(sessionID, observationID)
	target, exists := s.stickerObservations[targetKey]
	if !exists {
		return domain.StickerObservationState{}, domain.ErrNotFound
	}
	if !target.ExpiresAt.After(now) {
		delete(s.stickerObservations, targetKey)
		return domain.StickerObservationState{}, domain.ErrStickerObservationExpired
	}
	if len(target.Descriptor) != 0 {
		return cloneStickerObservation(target), nil
	}

	var candidate domain.StickerObservationState
	for key, state := range s.stickerObservations {
		if !state.ExpiresAt.After(now) {
			delete(s.stickerObservations, key)
			continue
		}
		if state.SessionID != sessionID || len(state.Descriptor) == 0 ||
			!aliasesIntersect(target.CorrelationAliases, state.CorrelationAliases) {
			continue
		}
		if candidate.ObservationID == "" || state.UpdatedAt.After(candidate.UpdatedAt) {
			candidate = state
		}
	}
	if candidate.ObservationID == "" {
		return domain.StickerObservationState{}, domain.ErrNotFound
	}
	return cloneStickerObservation(candidate), nil
}

func (s *Postgres) PutStickerObservation(ctx context.Context, state domain.StickerObservationState) error {
	if err := validateStickerObservation(state); err != nil {
		return err
	}
	if state.ExpiresAt.IsZero() {
		state.ExpiresAt = time.Now().UTC().Add(stickerObservationDefaultTTL)
	}
	var ciphertext, nonce []byte
	var err error
	if len(state.Descriptor) != 0 {
		ciphertext, nonce, err = s.box.Seal(state.Descriptor, stickerObservationAAD(state.SessionID, state.ObservationID))
		if err != nil {
			return fmt.Errorf("encrypt sticker observation: %w", err)
		}
	}
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin sticker observation: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()
	if _, err = tx.Exec(ctx, `
INSERT INTO wazync.sticker_observations (
    session_id, observation_id, descriptor_cipher, descriptor_nonce, expires_at, updated_at
) VALUES ($1, $2, $3, $4, $5, now())
ON CONFLICT (session_id, observation_id) DO UPDATE SET
    descriptor_cipher = COALESCE(EXCLUDED.descriptor_cipher, wazync.sticker_observations.descriptor_cipher),
    descriptor_nonce = COALESCE(EXCLUDED.descriptor_nonce, wazync.sticker_observations.descriptor_nonce),
    expires_at = GREATEST(EXCLUDED.expires_at, wazync.sticker_observations.expires_at),
    updated_at = now()`, state.SessionID, state.ObservationID, ciphertext, nonce, state.ExpiresAt); err != nil {
		return fmt.Errorf("persist sticker observation: %w", err)
	}
	if _, err = tx.Exec(ctx, `
DELETE FROM wazync.sticker_observation_aliases
WHERE session_id = $1 AND observation_id = $2`, state.SessionID, state.ObservationID); err != nil {
		return fmt.Errorf("replace sticker observation aliases: %w", err)
	}
	for _, alias := range state.CorrelationAliases {
		if _, err = tx.Exec(ctx, `
INSERT INTO wazync.sticker_observation_aliases (session_id, observation_id, alias_digest)
VALUES ($1, $2, $3)`, state.SessionID, state.ObservationID, alias); err != nil {
			return fmt.Errorf("persist sticker observation alias: %w", err)
		}
	}
	if _, err = tx.Exec(ctx, `DELETE FROM wazync.sticker_observations WHERE expires_at <= now()`); err != nil {
		return fmt.Errorf("prune sticker observations: %w", err)
	}
	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("commit sticker observation: %w", err)
	}
	return nil
}

func (s *Postgres) ResolveStickerObservation(
	ctx context.Context,
	sessionID string,
	observationID string,
	now time.Time,
) (domain.StickerObservationState, error) {
	var targetExpiry time.Time
	err := s.pool.QueryRow(ctx, `
SELECT expires_at FROM wazync.sticker_observations
WHERE session_id = $1 AND observation_id = $2`, sessionID, observationID).Scan(&targetExpiry)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.StickerObservationState{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.StickerObservationState{}, fmt.Errorf("find sticker observation: %w", err)
	}
	if !targetExpiry.After(now) {
		return domain.StickerObservationState{}, domain.ErrStickerObservationExpired
	}

	var state domain.StickerObservationState
	var ciphertext, nonce []byte
	err = s.pool.QueryRow(ctx, `
SELECT candidate.session_id, candidate.observation_id,
       candidate.descriptor_cipher, candidate.descriptor_nonce,
       candidate.updated_at, candidate.expires_at
FROM wazync.sticker_observations candidate
WHERE candidate.session_id = $1
  AND candidate.descriptor_cipher IS NOT NULL
  AND candidate.expires_at > $3
  AND (
      candidate.observation_id = $2
      OR EXISTS (
          SELECT 1
          FROM wazync.sticker_observation_aliases target_alias
          JOIN wazync.sticker_observation_aliases candidate_alias
            ON candidate_alias.session_id = target_alias.session_id
           AND candidate_alias.alias_digest = target_alias.alias_digest
          WHERE target_alias.session_id = $1
            AND target_alias.observation_id = $2
            AND candidate_alias.observation_id = candidate.observation_id
      )
  )
ORDER BY (candidate.observation_id = $2) DESC, candidate.updated_at DESC
LIMIT 1`, sessionID, observationID, now).Scan(
		&state.SessionID, &state.ObservationID, &ciphertext, &nonce, &state.UpdatedAt, &state.ExpiresAt,
	)
	if errors.Is(err, pgx.ErrNoRows) {
		return domain.StickerObservationState{}, domain.ErrNotFound
	}
	if err != nil {
		return domain.StickerObservationState{}, fmt.Errorf("resolve sticker observation: %w", err)
	}
	state.Descriptor, err = s.box.Open(ciphertext, nonce, stickerObservationAAD(state.SessionID, state.ObservationID))
	if err != nil {
		return domain.StickerObservationState{}, fmt.Errorf("decrypt sticker observation: %w", err)
	}
	return state, nil
}

func validateStickerObservation(state domain.StickerObservationState) error {
	if state.SessionID == "" || state.ObservationID == "" || len(state.CorrelationAliases) == 0 {
		return errors.New("invalid sticker observation")
	}
	for _, alias := range state.CorrelationAliases {
		if len(alias) != 64 {
			return errors.New("invalid sticker correlation alias")
		}
	}
	return nil
}

func aliasesIntersect(first, second []string) bool {
	for _, alias := range first {
		if slices.Contains(second, alias) {
			return true
		}
	}
	return false
}

func cloneStickerObservation(state domain.StickerObservationState) domain.StickerObservationState {
	state.Descriptor = append([]byte(nil), state.Descriptor...)
	state.CorrelationAliases = append([]string(nil), state.CorrelationAliases...)
	return state
}

func stickerObservationKey(sessionID, observationID string) string {
	return sessionID + "\x00" + observationID
}

func stickerObservationAAD(sessionID, observationID string) []byte {
	return []byte(stickerObservationKey(sessionID, observationID))
}
