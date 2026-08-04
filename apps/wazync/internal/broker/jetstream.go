package broker

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"github.com/nats-io/nats.go"
)

const commandBatchSize = 1

type Config struct {
	URL             string
	User            string
	Password        string
	Stream          string
	EventSubject    string
	CommandSubject  string
	CommandConsumer string
	MaxBodyBytes    int64
}

// JetStream is the durable transport around the existing database-backed
// command and event stores. JetStream acknowledgements never replace those
// idempotency boundaries.
type JetStream struct {
	connection *nats.Conn
	stream     nats.JetStreamContext
	config     Config
	store      store.Store
}

func (j *JetStream) Connected() bool {
	return j != nil && j.connection != nil && j.connection.IsConnected()
}

func (j *JetStream) QueueMetrics(ctx context.Context) (streamMessages, commandPending, commandAckPending uint64, err error) {
	streamInfo, err := j.stream.StreamInfo(j.config.Stream, nats.Context(ctx))
	if err != nil {
		return 0, 0, 0, fmt.Errorf("inspect JetStream metrics: %w", err)
	}
	consumerInfo, err := j.stream.ConsumerInfo(j.config.Stream, j.config.CommandConsumer, nats.Context(ctx))
	if err != nil && !errors.Is(err, nats.ErrConsumerNotFound) {
		return 0, 0, 0, fmt.Errorf("inspect command consumer metrics: %w", err)
	}
	if consumerInfo == nil {
		return streamInfo.State.Msgs, 0, 0, nil
	}
	return streamInfo.State.Msgs, consumerInfo.NumPending, uint64(max(consumerInfo.NumAckPending, 0)), nil
}

func Open(config Config, persistence store.Store) (*JetStream, error) {
	options := []nats.Option{
		nats.Name("kontivehub-wazync"),
		nats.Timeout(5 * time.Second),
		nats.MaxReconnects(-1),
		nats.ReconnectWait(time.Second),
	}
	if config.User != "" {
		options = append(options, nats.UserInfo(config.User, config.Password))
	}
	connection, err := nats.Connect(config.URL, options...)
	if err != nil {
		return nil, fmt.Errorf("connect NATS: %w", err)
	}
	stream, err := connection.JetStream(nats.PublishAsyncMaxPending(256))
	if err != nil {
		connection.Close()
		return nil, fmt.Errorf("open JetStream: %w", err)
	}
	transport := &JetStream{connection: connection, stream: stream, config: config, store: persistence}
	if err := transport.ensureStream(); err != nil {
		connection.Close()
		return nil, err
	}
	return transport, nil
}

func (j *JetStream) ensureStream() error {
	desired := &nats.StreamConfig{
		Name:       j.config.Stream,
		Subjects:   []string{j.config.EventSubject, j.config.CommandSubject},
		Retention:  nats.WorkQueuePolicy,
		Storage:    nats.FileStorage,
		Discard:    nats.DiscardOld,
		MaxAge:     14 * 24 * time.Hour,
		Duplicates: 24 * time.Hour,
		MaxMsgSize: int32(j.config.MaxBodyBytes),
	}
	info, err := j.stream.StreamInfo(j.config.Stream)
	if errors.Is(err, nats.ErrStreamNotFound) {
		_, err = j.stream.AddStream(desired)
		if err != nil {
			return fmt.Errorf("create JetStream stream: %w", err)
		}
		return nil
	}
	if err != nil {
		return fmt.Errorf("inspect JetStream stream: %w", err)
	}
	if err := streamConfigDrift(info.Config, *desired); err != nil {
		return err
	}
	return nil
}

func streamConfigDrift(actual, desired nats.StreamConfig) error {
	drift := make([]string, 0, 6)
	if !sameSubjects(actual.Subjects, desired.Subjects) {
		drift = append(drift, "subjects")
	}
	if actual.Retention != desired.Retention {
		drift = append(drift, "retention")
	}
	if actual.Storage != desired.Storage {
		drift = append(drift, "storage")
	}
	if actual.Discard != desired.Discard {
		drift = append(drift, "discard")
	}
	if actual.MaxAge != desired.MaxAge {
		drift = append(drift, "max_age")
	}
	if actual.Duplicates != desired.Duplicates {
		drift = append(drift, "duplicates")
	}
	if actual.MaxMsgSize != desired.MaxMsgSize {
		drift = append(drift, "max_message_size")
	}
	if len(drift) != 0 {
		return fmt.Errorf("JetStream stream %q configuration drift: %s", desired.Name, strings.Join(drift, ", "))
	}
	return nil
}

func sameSubjects(actual, desired []string) bool {
	if len(actual) != len(desired) {
		return false
	}
	seen := make(map[string]bool, len(actual))
	for _, subject := range actual {
		seen[subject] = true
	}
	for _, subject := range desired {
		if !seen[subject] {
			return false
		}
	}
	return true
}

func (j *JetStream) PublishEvent(ctx context.Context, event domain.Event) error {
	body, err := json.Marshal(event)
	if err != nil {
		return err
	}
	message := nats.NewMsg(j.config.EventSubject)
	message.Data = body
	message.Header.Set(nats.MsgIdHdr, event.EventID)
	if _, err := j.stream.PublishMsg(message, nats.Context(ctx)); err != nil {
		return fmt.Errorf("publish gateway event: %w", err)
	}
	return nil
}

func (j *JetStream) RunCommandConsumer(ctx context.Context) error {
	if err := j.ensureCommandConsumer(ctx); err != nil {
		return err
	}
	subscription, err := j.stream.PullSubscribe(
		j.config.CommandSubject,
		j.config.CommandConsumer,
		nats.Bind(j.config.Stream, j.config.CommandConsumer),
		nats.ManualAck(),
	)
	if err != nil {
		return fmt.Errorf("create command consumer: %w", err)
	}
	defer subscription.Unsubscribe()

	for ctx.Err() == nil {
		messages, fetchErr := subscription.Fetch(commandBatchSize, nats.MaxWait(time.Second))
		if fetchErr != nil && !errors.Is(fetchErr, nats.ErrTimeout) && !errors.Is(fetchErr, context.Canceled) {
			slog.Warn("JetStream command fetch failed", "error", fetchErr.Error())
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(time.Second):
			}
			continue
		}
		for _, message := range messages {
			j.consumeCommand(ctx, message)
		}
	}
	return ctx.Err()
}

func (j *JetStream) ensureCommandConsumer(ctx context.Context) error {
	desired := nats.ConsumerConfig{
		Durable: j.config.CommandConsumer, DeliverPolicy: nats.DeliverAllPolicy,
		AckPolicy: nats.AckExplicitPolicy, AckWait: 2 * time.Minute, MaxDeliver: -1,
		FilterSubject: j.config.CommandSubject, ReplayPolicy: nats.ReplayInstantPolicy,
		MaxAckPending: commandBatchSize,
	}
	info, err := j.stream.ConsumerInfo(j.config.Stream, j.config.CommandConsumer, nats.Context(ctx))
	if errors.Is(err, nats.ErrConsumerNotFound) {
		if _, err := j.stream.AddConsumer(j.config.Stream, &desired, nats.Context(ctx)); err != nil {
			return fmt.Errorf("create command consumer: %w", err)
		}
		return nil
	}
	if err != nil {
		return fmt.Errorf("inspect command consumer: %w", err)
	}
	if err := commandConsumerConfigDrift(info.Config, desired); err != nil {
		return err
	}
	actual := info.Config
	if actual.AckWait == desired.AckWait && actual.MaxDeliver == desired.MaxDeliver &&
		actual.MaxAckPending == desired.MaxAckPending {
		return nil
	}
	actual.AckWait = desired.AckWait
	actual.MaxDeliver = desired.MaxDeliver
	actual.MaxAckPending = desired.MaxAckPending
	if _, err := j.stream.UpdateConsumer(j.config.Stream, &actual, nats.Context(ctx)); err != nil {
		return fmt.Errorf("migrate command consumer: %w", err)
	}
	return nil
}

func commandConsumerConfigDrift(actual, desired nats.ConsumerConfig) error {
	drift := make([]string, 0, 6)
	if actual.Durable != desired.Durable {
		drift = append(drift, "durable")
	}
	if actual.DeliverPolicy != desired.DeliverPolicy {
		drift = append(drift, "deliver_policy")
	}
	if actual.AckPolicy != desired.AckPolicy {
		drift = append(drift, "ack_policy")
	}
	if actual.FilterSubject != desired.FilterSubject {
		drift = append(drift, "filter_subject")
	}
	if actual.ReplayPolicy != desired.ReplayPolicy {
		drift = append(drift, "replay_policy")
	}
	if actual.DeliverSubject != "" {
		drift = append(drift, "delivery_mode")
	}
	if len(drift) != 0 {
		return fmt.Errorf("JetStream consumer %q configuration drift: %s", desired.Durable, strings.Join(drift, ", "))
	}
	return nil
}

func (j *JetStream) consumeCommand(ctx context.Context, message *nats.Msg) {
	if int64(len(message.Data)) > j.config.MaxBodyBytes {
		_ = message.Term()
		return
	}
	command, err := decodeCommand(message.Data)
	if err != nil {
		_ = message.Term()
		return
	}
	command.AcceptedAt = time.Now().UTC()
	command.Digest, err = canonicalDigest(message.Data)
	if err != nil {
		_ = message.Term()
		return
	}
	_, err = j.store.AcceptCommand(ctx, command)
	if errors.Is(err, domain.ErrDigestConflict) {
		slog.Warn("JetStream command digest conflict", "command_id", command.CommandID)
		_ = message.Term()
		return
	}
	if err != nil {
		_ = message.NakWithDelay(time.Second)
		return
	}
	_ = message.Ack()
}

func decodeCommand(body []byte) (domain.Command, error) {
	var command domain.Command
	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&command); err != nil {
		return domain.Command{}, err
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return domain.Command{}, errors.New("unexpected command content")
	}
	if !command.Valid() || command.ValidatePayload() != nil {
		return domain.Command{}, errors.New("invalid command")
	}
	if err := protocol.ValidateCommandRecipientScope(command); err != nil {
		return domain.Command{}, err
	}
	return command, nil
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

func (j *JetStream) Close() {
	if j == nil || j.connection == nil {
		return
	}
	_ = j.connection.Drain()
	j.connection.Close()
}
