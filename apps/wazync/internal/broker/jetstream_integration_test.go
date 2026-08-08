package broker

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"github.com/nats-io/nats.go"
)

func TestJetStreamPublishesAndConsumesWithStableIdempotencyKeys(t *testing.T) {
	url := os.Getenv("WAZYNC_TEST_NATS_URL")
	if url == "" {
		t.Skip("WAZYNC_TEST_NATS_URL is not configured")
	}

	now := time.Now().UTC()
	suffix := now.Format("20060102T150405") + fmt.Sprintf("%09d", now.Nanosecond())
	config := Config{
		URL: url, Stream: "WAZYNC_TEST_" + suffix,
		EventSubject:    "wazync.test." + suffix + ".events",
		CommandSubject:  "wazync.test." + suffix + ".commands",
		CommandConsumer: "wazync-test-commands", MaxBodyBytes: 1 << 20,
	}
	persistence := store.NewMemory()
	transport, err := Open(config, persistence)
	if err != nil {
		t.Fatalf("open JetStream test transport: %v", err)
	}
	defer transport.Close()
	defer transport.stream.DeleteStream(config.Stream)

	event := domain.Event{
		ContractVersion: "v1", EventID: "event-jetstream-idempotent", SessionID: "session-jetstream-test",
		Type: domain.EventSessionStatusChanged, OccurredAt: time.Now().UTC(),
		Payload: json.RawMessage(`{"status":"CONNECTED"}`), Digest: "digest-event-jetstream",
	}
	if err := transport.PublishEvent(t.Context(), event); err != nil {
		t.Fatalf("publish event: %v", err)
	}
	if err := transport.PublishEvent(t.Context(), event); err != nil {
		t.Fatalf("publish duplicate event: %v", err)
	}
	if _, err := transport.stream.AddConsumer(config.Stream, &nats.ConsumerConfig{
		Durable: config.CommandConsumer, DeliverPolicy: nats.DeliverAllPolicy,
		AckPolicy: nats.AckExplicitPolicy, AckWait: 2 * time.Minute, MaxDeliver: 20,
		FilterSubject: config.CommandSubject, ReplayPolicy: nats.ReplayInstantPolicy,
		MaxAckPending: 1_000,
	}); err != nil {
		t.Fatalf("provision legacy command consumer: %v", err)
	}

	command := []byte(`{"contract_version":"v1","command_id":"command-jetstream-idempotent","session_id":"session-jetstream-test","type":"SESSION_CONNECT","payload":{}}`)
	message := nats.NewMsg(config.CommandSubject)
	message.Data = command
	message.Header.Set(nats.MsgIdHdr, "command-jetstream-idempotent")
	if _, err := transport.stream.PublishMsg(message); err != nil {
		t.Fatalf("publish command: %v", err)
	}
	if _, err := transport.stream.PublishMsg(message); err != nil {
		t.Fatalf("publish duplicate command: %v", err)
	}

	ctx, cancel := context.WithCancel(t.Context())
	done := make(chan error, 1)
	go func() { done <- transport.RunCommandConsumer(ctx) }()
	deadline := time.Now().Add(5 * time.Second)
	for {
		select {
		case consumerErr := <-done:
			if consumerErr == nil {
				t.Fatal("command consumer stopped before accepting the command")
			}
			t.Fatalf("command consumer stopped before accepting the command: %v", consumerErr)
		default:
		}
		metrics, metricsErr := persistence.Metrics(t.Context())
		if metricsErr != nil {
			t.Fatalf("read command metrics: %v", metricsErr)
		}
		if metrics.PendingCommands == 1 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("command was not durably accepted exactly once: %+v", metrics)
		}
		time.Sleep(25 * time.Millisecond)
	}
	cancel()
	if consumerErr := <-done; consumerErr != nil && !errors.Is(consumerErr, context.Canceled) {
		t.Fatalf("stop command consumer: %v", consumerErr)
	}

	info, err := transport.stream.StreamInfo(config.Stream)
	if err != nil {
		t.Fatalf("inspect stream: %v", err)
	}
	if info.State.Msgs != 1 {
		t.Fatalf("duplicate window or explicit ACK failed: stream messages=%d", info.State.Msgs)
	}
	consumerInfo, err := transport.stream.ConsumerInfo(config.Stream, config.CommandConsumer)
	if err != nil {
		t.Fatalf("inspect command consumer: %v", err)
	}
	if consumerInfo.Config.MaxDeliver != -1 {
		t.Fatalf("transient command failures can exhaust delivery: max_deliver=%d", consumerInfo.Config.MaxDeliver)
	}
}

func TestStreamConfigDriftRejectsEveryRequiredSetting(t *testing.T) {
	desired := nats.StreamConfig{
		Name: "KONTIVEHUB_WHATSAPP", Subjects: []string{"events", "commands"},
		Retention: nats.WorkQueuePolicy, Storage: nats.FileStorage, Discard: nats.DiscardOld,
		MaxAge: 14 * 24 * time.Hour, Duplicates: 24 * time.Hour, MaxMsgSize: 1 << 20,
	}
	tests := map[string]func(*nats.StreamConfig){
		"subjects":         func(config *nats.StreamConfig) { config.Subjects = []string{"events"} },
		"retention":        func(config *nats.StreamConfig) { config.Retention = nats.LimitsPolicy },
		"storage":          func(config *nats.StreamConfig) { config.Storage = nats.MemoryStorage },
		"discard":          func(config *nats.StreamConfig) { config.Discard = nats.DiscardNew },
		"max_age":          func(config *nats.StreamConfig) { config.MaxAge = time.Hour },
		"duplicates":       func(config *nats.StreamConfig) { config.Duplicates = time.Minute },
		"max_message_size": func(config *nats.StreamConfig) { config.MaxMsgSize = 2 << 20 },
	}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			actual := desired
			actual.Subjects = append([]string(nil), desired.Subjects...)
			mutate(&actual)
			if err := streamConfigDrift(actual, desired); err == nil {
				t.Fatalf("configuration drift %s was accepted", name)
			}
		})
	}
	if err := streamConfigDrift(desired, desired); err != nil {
		t.Fatalf("matching stream configuration was rejected: %v", err)
	}
}

func TestCommandConsumerConfigDriftAllowsOnlyMutableDeliverySettings(t *testing.T) {
	desired := nats.ConsumerConfig{
		Durable: "wazync-commands", DeliverPolicy: nats.DeliverAllPolicy,
		AckPolicy: nats.AckExplicitPolicy, AckWait: 2 * time.Minute, MaxDeliver: -1,
		FilterSubject: "kontivehub.whatsapp.commands", ReplayPolicy: nats.ReplayInstantPolicy,
		MaxAckPending: 1,
	}
	legacy := desired
	legacy.MaxDeliver = 20
	legacy.MaxAckPending = 1_000
	if err := commandConsumerConfigDrift(legacy, desired); err != nil {
		t.Fatalf("mutable legacy settings were rejected: %v", err)
	}
	legacy.FilterSubject = "unexpected.commands"
	if err := commandConsumerConfigDrift(legacy, desired); err == nil {
		t.Fatal("immutable consumer drift was accepted")
	}
}
