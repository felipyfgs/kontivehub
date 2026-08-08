package dispatcher

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

type Dispatcher struct {
	store          store.Store
	client         *http.Client
	eventIngestURL string
	keyID          string
	secret         string
	batchSize      int
	maxAttempts    int
	now            func() time.Time
	spool          interface{ Ack(string) error }
	publisher      interface {
		PublishEvent(context.Context, domain.Event) error
	}
}

func (d *Dispatcher) WithSpool(spool interface{ Ack(string) error }) *Dispatcher {
	d.spool = spool
	return d
}

func (d *Dispatcher) WithPublisher(publisher interface {
	PublishEvent(context.Context, domain.Event) error
}) *Dispatcher {
	d.publisher = publisher
	return d
}

func New(
	persistence store.Store,
	eventIngestURL, keyID, secret string,
	client *http.Client,
) *Dispatcher {
	if client == nil {
		client = &http.Client{Timeout: 15 * time.Second}
	}
	return &Dispatcher{
		store: persistence, client: client, eventIngestURL: eventIngestURL, keyID: keyID, secret: secret,
		batchSize: 50, maxAttempts: 10, now: time.Now,
	}
}

func (d *Dispatcher) Run(ctx context.Context, every time.Duration) {
	ticker := time.NewTicker(every)
	defer ticker.Stop()
	for {
		_ = d.DispatchOnce(ctx)
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

func (d *Dispatcher) DispatchOnce(ctx context.Context) error {
	now := d.now().UTC()
	pending, err := d.store.NextEvents(ctx, d.batchSize, now)
	if err != nil {
		return err
	}
	for _, item := range pending {
		if err := d.deliver(ctx, item.Event, now); err != nil {
			terminal := item.Attempts >= d.maxAttempts
			availableAt := now.Add(retryDelay(item.Attempts))
			if markErr := d.store.MarkEventFailed(ctx, item.Event.EventID, availableAt, deliveryErrorCode(err), terminal); markErr != nil {
				return markErr
			}
			continue
		}
		// A JetStream publish acknowledgement only proves that the event is
		// durable in NATS. Laravel still needs the encrypted spool object to
		// ingest the media, so its consumer acknowledges the spool explicitly
		// after the database transaction commits.
		ackErr := error(nil)
		if d.publisher == nil {
			_, ackErr = d.ackSpool(item.Event)
		}
		if ackErr != nil {
			if markErr := d.store.MarkEventFailed(
				ctx, item.Event.EventID, now.Add(retryDelay(item.Attempts)), "MEDIA_SPOOL_ACK_FAILED", false,
			); markErr != nil {
				return markErr
			}
			continue
		}
		if err := d.ackMediaRetryState(ctx, item.Event); err != nil {
			if markErr := d.store.MarkEventFailed(
				ctx, item.Event.EventID, now.Add(retryDelay(item.Attempts)), "MEDIA_RETRY_STATE_ACK_FAILED", false,
			); markErr != nil {
				return markErr
			}
			continue
		}
		if err := d.store.MarkEventDelivered(ctx, item.Event.EventID, now); err != nil {
			return err
		}
	}
	return nil
}

func (d *Dispatcher) ackMediaRetryState(ctx context.Context, event domain.Event) error {
	if event.Type != domain.EventMediaRetryUpdated {
		return nil
	}
	var payload struct {
		ProviderMessageID string `json:"provider_message_id"`
		Status            string `json:"status"`
	}
	if err := json.Unmarshal(event.Payload, &payload); err != nil ||
		payload.Status != "READY" || payload.ProviderMessageID == "" {
		return nil
	}
	return d.store.DeleteMediaRetryState(ctx, event.SessionID, payload.ProviderMessageID)
}

func (d *Dispatcher) ackSpool(event domain.Event) (bool, error) {
	if d.spool == nil {
		return false, nil
	}
	var payload struct {
		SpoolID string `json:"spool_id"`
	}
	if err := json.Unmarshal(event.Payload, &payload); err != nil || payload.SpoolID == "" {
		return false, nil
	}
	if err := d.spool.Ack(payload.SpoolID); err != nil {
		return false, err
	}
	return true, nil
}

func (d *Dispatcher) deliver(ctx context.Context, event domain.Event, now time.Time) error {
	if d.publisher != nil {
		return d.publisher.PublishEvent(ctx, event)
	}
	body, err := json.Marshal(event)
	if err != nil {
		return err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, d.eventIngestURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	nonce := randomNonce()
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set(security.HeaderKeyID, d.keyID)
	request.Header.Set(security.HeaderTimestamp, fmt.Sprintf("%d", now.Unix()))
	request.Header.Set(security.HeaderNonce, nonce)
	request.Header.Set(security.HeaderSignature, security.Sign(
		d.secret, request.Method, request.URL.EscapedPath(), body, now.Unix(), nonce,
	))
	response, err := d.client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4<<10))
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return fmt.Errorf("Laravel event endpoint returned status %d", response.StatusCode)
	}
	return nil
}

func randomNonce() string {
	value := make([]byte, 16)
	_, _ = rand.Read(value)
	return hex.EncodeToString(value)
}

func retryDelay(attempt int) time.Duration {
	if attempt < 1 {
		attempt = 1
	}
	return min(time.Second*time.Duration(1<<min(attempt-1, 8)), 5*time.Minute)
}

func deliveryErrorCode(err error) string {
	if err == nil {
		return ""
	}
	return "LARAVEL_EVENT_DELIVERY_FAILED"
}
