package domain

import (
	"encoding/json"
	"testing"
)

func TestMediaRetryPayloadAcceptsOnlyLegacyInboundOrV2(t *testing.T) {
	t.Parallel()
	valid := []string{
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":false}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"INBOUND"}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"OUTBOUND"}`,
	}
	for _, raw := range valid {
		command := Command{Type: CommandRetryMedia, Payload: json.RawMessage(raw)}
		if err := command.ValidatePayload(); err != nil {
			t.Fatalf("valid retry payload rejected: %s: %v", raw, err)
		}
	}
	invalid := []string{
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":null,"sender":"+5511999991234","from_me":false}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"","sender":"+5511999991234","from_me":false}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234"}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":true}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":null}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"INBOUND","sender":"+5511999991234"}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"SIDEWAYS"}`,
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"OUTBOUND","media_key":"secret"}`,
	}
	for _, raw := range invalid {
		command := Command{Type: CommandRetryMedia, Payload: json.RawMessage(raw)}
		if err := command.ValidatePayload(); err == nil {
			t.Fatalf("invalid retry payload accepted: %s", raw)
		}
	}
}

func TestMediaRetryPayloadMarshalPreservesLegacyFalseWithoutPollutingV2(t *testing.T) {
	t.Parallel()
	legacy, err := json.Marshal(MediaRetryPayload{
		To:              "+5511999991234",
		TargetMessageID: "provider-media-0001",
		Sender:          "+5511999991234",
		FromMe:          false,
	})
	if err != nil {
		t.Fatalf("marshal legacy payload: %v", err)
	}
	if string(legacy) != `{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":false}` {
		t.Fatalf("legacy false was not preserved: %s", legacy)
	}

	v2, err := json.Marshal(MediaRetryPayload{
		To:                "+5511999991234",
		TargetMessageID:   "provider-media-0001",
		ExpectedDirection: "OUTBOUND",
	})
	if err != nil {
		t.Fatalf("marshal v2 payload: %v", err)
	}
	if string(v2) != `{"to":"+5511999991234","target_message_id":"provider-media-0001","expected_direction":"OUTBOUND"}` {
		t.Fatalf("v2 payload leaked legacy fields: %s", v2)
	}
}
