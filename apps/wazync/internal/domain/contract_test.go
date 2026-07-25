package domain

import (
	"encoding/json"
	"testing"
)

func TestOneToOneCommandAndQueryFamiliesAreExplicit(t *testing.T) {
	t.Parallel()

	commands := []CommandType{
		CommandProvisionSession, CommandPairSession, CommandPairPhone, CommandPasskeyRespond,
		CommandPasskeyConfirm, CommandConnectSession, CommandDisconnectSession, CommandResetSession,
		CommandSetPassive, CommandLogoutSession, CommandSendMessage, CommandEditMessage,
		CommandRevokeMessage, CommandReactMessage, CommandVotePoll, CommandMarkMessage,
		CommandRequestUnavailable, CommandRetryMedia, CommandSetPresence, CommandSubscribePresence,
		CommandSetChatPresence, CommandSetDisappearing, CommandUpdateChatState,
		CommandUpdateBlocklist, CommandUpdatePrivacy, CommandSetDefaultDisappearing,
		CommandRequestHistorySync,
	}
	for _, command := range commands {
		if !command.Valid() {
			t.Errorf("command %s is not valid", command)
		}
	}
	queries := []QueryType{
		QueryIsOnWhatsApp, QueryUserInfo, QueryBusinessProfile, QueryProfilePicture,
		QueryContactQRLink, QueryResolveContactQR, QueryResolveBusinessURL,
		QueryBlocklist, QueryPrivacySettings,
	}
	for _, query := range queries {
		if !query.Valid() {
			t.Errorf("query %s is not valid", query)
		}
	}
	if CommandType("GROUP_CREATE").Valid() || QueryType("NEWSLETTER_INFO").Valid() {
		t.Fatal("group or newsletter operation entered the 1:1 contract")
	}
}

func TestSessionStatusContractNormalizesLegacyValues(t *testing.T) {
	t.Parallel()
	for _, status := range []SessionStatus{SessionDisconnected, SessionConnecting, SessionConnected} {
		if !status.Valid() {
			t.Fatalf("canonical status %s is invalid", status)
		}
	}
	for legacy, expected := range map[string]SessionStatus{
		"PAIRING":     SessionConnecting,
		"DISABLED":    SessionDisconnected,
		"PROVISIONED": SessionDisconnected,
		"DEGRADED":    SessionDisconnected,
		"REVOKED":     SessionDisconnected,
		"LOGGED_OUT":  SessionDisconnected,
	} {
		if got := NormalizeSessionStatus(legacy); got != expected {
			t.Fatalf("normalize %s: got %s want %s", legacy, got, expected)
		}
	}
	if SessionStatus("PAIRING").Valid() || SessionStatus("LOGOUT").Valid() {
		t.Fatal("legacy/logout value entered the persisted status contract")
	}
}

func TestPayloadValidationRejectsUnknownNestedFields(t *testing.T) {
	t.Parallel()

	valid := Command{Type: CommandSendMessage, Payload: json.RawMessage(
		`{"to":"+5511999991234","kind":"DOCUMENT","text":"guia","media":{"attachment_id":1,"filename":"guia.pdf","mime_type":"application/pdf","size_bytes":10,"sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}`,
	)}
	if err := valid.ValidatePayload(); err != nil {
		t.Fatalf("valid payload was rejected: %v", err)
	}
	invalid := valid
	invalid.Payload = json.RawMessage(`{"to":"+5511999991234","raw_proto":true}`)
	if err := invalid.ValidatePayload(); err == nil {
		t.Fatal("unknown message payload field was accepted")
	}

	query := Query{Type: QueryProfilePicture, Payload: json.RawMessage(
		`{"user":"+5511999991234","preview":true,"device_jid":true}`,
	)}
	if err := query.ValidatePayload(); err == nil {
		t.Fatal("query accepted a protocol-internal field")
	}
}

func TestChatStatePayloadSeparatesGlobalSyncFromOneToOneActions(t *testing.T) {
	t.Parallel()

	for name, payload := range map[string]string{
		"sync":       `{"action":"SYNC"}`,
		"mark clean": `{"action":"MARK_CLEAN","timestamp":1785000000}`,
		"archive":    `{"action":"ARCHIVE","to":"+5511999991234","value":true}`,
	} {
		t.Run(name, func(t *testing.T) {
			command := Command{Type: CommandUpdateChatState, Payload: json.RawMessage(payload)}
			if err := command.ValidatePayload(); err != nil {
				t.Fatalf("valid chat-state payload was rejected: %v", err)
			}
		})
	}

	for name, payload := range map[string]string{
		"scoped action without recipient": `{"action":"ARCHIVE","value":true}`,
		"mark clean without timestamp":    `{"action":"MARK_CLEAN"}`,
		"unknown action":                  `{"action":"RAW_PATCH"}`,
	} {
		t.Run(name, func(t *testing.T) {
			command := Command{Type: CommandUpdateChatState, Payload: json.RawMessage(payload)}
			if err := command.ValidatePayload(); err == nil {
				t.Fatal("invalid chat-state payload was accepted")
			}
		})
	}
}

func TestRecoveryPayloadsDoNotExposeMediaSecrets(t *testing.T) {
	t.Parallel()

	history := Command{Type: CommandRequestHistorySync, Payload: json.RawMessage(
		`{"to":"+5511999991234","last_message_id":"provider-cursor-0001","last_message_from":"+5511999991234","last_message_timestamp":1700000000,"last_message_from_me":false,"count":50}`,
	)}
	if err := history.ValidatePayload(); err != nil {
		t.Fatalf("valid history cursor was rejected: %v", err)
	}
	history.Payload = json.RawMessage(
		`{"to":"+5511999991234","last_message_id":"provider-cursor-0001","last_message_from":"+5511999991234","last_message_timestamp":1700000000,"last_message_from_me":false,"count":51}`,
	)
	if err := history.ValidatePayload(); err == nil {
		t.Fatal("history request above the official batch limit was accepted")
	}

	retry := Command{Type: CommandRetryMedia, Payload: json.RawMessage(
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":false}`,
	)}
	if err := retry.ValidatePayload(); err != nil {
		t.Fatalf("valid media retry was rejected: %v", err)
	}
	retry.Payload = json.RawMessage(
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","from_me":false}`,
	)
	if err := retry.ValidatePayload(); err == nil {
		t.Fatal("media retry without sender was accepted")
	}
	retry.Payload = json.RawMessage(
		`{"to":"+5511999991234","target_message_id":"provider-media-0001","sender":"+5511999991234","from_me":false,"media_key":"must-not-cross-contract"}`,
	)
	if err := retry.ValidatePayload(); err == nil {
		t.Fatal("media retry contract accepted a media key")
	}
}
