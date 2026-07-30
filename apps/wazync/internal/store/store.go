package store

import (
	"context"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
)

type Store interface {
	AcceptCommand(context.Context, domain.Command) (duplicate bool, err error)
	NextCommands(context.Context, string, int, time.Time) ([]domain.PendingCommand, error)
	MarkCommandProcessed(context.Context, string, time.Time) error
	MarkCommandFailed(context.Context, string, time.Time, string, bool) error
	// Media retry receipts are fenced by the command attempt that acquired the
	// durable command lock. A delayed worker must not acknowledge an attempt
	// that has already been reclaimed by another worker.
	FinalizeMediaRetryCommandProcessed(ctx context.Context, commandID string, expectedAttempts int, processedAt time.Time) error
	FinalizeMediaRetryCommandFailed(ctx context.Context, commandID string, expectedAttempts int, availableAt time.Time, errorCode string) error
	FinalizeCommandFailureWithEvent(ctx context.Context, commandID string, expectedAttempts int, availableAt time.Time, errorCode string, event domain.Event) error
	AppendEvent(context.Context, domain.Event) (duplicate bool, err error)
	NextEvents(context.Context, int, time.Time) ([]domain.PendingEvent, error)
	MarkEventDelivered(context.Context, string, time.Time) error
	MarkEventFailed(context.Context, string, time.Time, string, bool) error
	ClaimNonce(context.Context, string, time.Time) (bool, error)
	PutMediaRetryState(context.Context, domain.MediaRetryState) error
	GetMediaRetryState(context.Context, string, string) (domain.MediaRetryState, error)
	CompareAndBeginMediaRetry(context.Context, domain.MediaRetryState, time.Time) (domain.MediaRetryState, bool, error)
	DeleteMediaRetryState(context.Context, string, string) error
	UpsertSession(context.Context, domain.Session) error
	GetSession(context.Context, string) (domain.Session, error)
	SetSessionStatus(context.Context, string, domain.SessionStatus, int, time.Time) error
	ClaimSessions(context.Context, string, int, time.Time, time.Duration) ([]domain.Lease, error)
	ClaimSession(context.Context, string, string, int, time.Time, time.Duration) (domain.Lease, bool, error)
	ListReplicaLeases(context.Context, string, time.Time) ([]domain.Lease, error)
	RenewLease(context.Context, domain.Lease, time.Time, time.Duration) (domain.Lease, error)
	ValidLease(context.Context, domain.Lease, time.Time) (bool, error)
	ReleaseLease(context.Context, domain.Lease) error
	Ping(context.Context) error
	Metrics(context.Context) (domain.Metrics, error)
	Close()
}
