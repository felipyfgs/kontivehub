package domain

import (
	"encoding/json"
	"testing"
)

func TestOneToOneCommandAndQueryFamiliesAreExplicit(t *testing.T) {
	t.Parallel()

	commands := []CommandType{
		CommandProvisionSession, CommandPairSession, CommandPairPhone, CommandPasskeyRespond,
		CommandPasskeyConfirm, CommandConnectSession, CommandDisconnectSession,
		CommandSetPassive, CommandLogoutSession, CommandSendMessage, CommandEditMessage,
		CommandRevokeMessage, CommandReactMessage, CommandVotePoll, CommandMarkMessage,
		CommandRequestUnavailableMessage, CommandRetryMedia, CommandSetPresence, CommandSubscribePresence,
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
		QueryIsOnWhatsApp, QueryUserInfo, QueryContactProfiles, QueryBusinessProfile, QueryProfilePicture,
		QueryContactQRLink, QueryResolveContactQR, QueryResolveBusinessLink,
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

func TestSessionStatusContractAcceptsOnlyCanonicalValues(t *testing.T) {
	t.Parallel()
	for _, status := range []SessionStatus{SessionDisconnected, SessionConnecting, SessionConnected} {
		if !status.Valid() {
			t.Fatalf("canonical status %s is invalid", status)
		}
	}
	for _, invalid := range []SessionStatus{"PAIRING", "DISABLED", "PROVISIONED", "DEGRADED", "REVOKED", "LOGGED_OUT"} {
		if invalid.Valid() {
			t.Fatalf("non-canonical status %s entered the persisted contract", invalid)
		}
	}
}

func TestMessageActionResultIsAnExplicitEventFamily(t *testing.T) {
	t.Parallel()
	if !EventMessageActionResult.Valid() {
		t.Fatal("terminal message action result is not a valid event type")
	}
	if EventType("MESSAGE_ACTION_RESULT_V2").Valid() {
		t.Fatal("unversioned action result variant entered the contract")
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
	invalid.Payload = json.RawMessage(`{"to":"+5511999991234","text":"sem kind"}`)
	if err := invalid.ValidatePayload(); err == nil {
		t.Fatal("untyped message payload was accepted")
	}

	query := Query{Type: QueryProfilePicture, Payload: json.RawMessage(
		`{"user":"+5511999991234","preview":true,"device_jid":true}`,
	)}
	if err := query.ValidatePayload(); err == nil {
		t.Fatal("query accepted a protocol-internal field")
	}
}

func TestOutboundMessageDTOsAllowOnlyCompatibleVariantsAndOrderedBatches(t *testing.T) {
	t.Parallel()

	valid := MessageSendPayload{To: "+5511999991234", Kind: MessageVideo, Media: &MediaReference{
		Filename: "animacao.mp4", MIMEType: "video/mp4", GIF: true,
	}}
	if err := valid.Validate(); err != nil {
		t.Fatalf("valid GIF video was rejected: %v", err)
	}
	invalid := valid
	invalid.Kind = MessageImage
	if err := invalid.Validate(); err == nil {
		t.Fatal("GIF image was accepted")
	}
	invalid = valid
	invalid.Media = &MediaReference{Filename: "video.mp4", MIMEType: "video/mp4", GIF: true, ViewOnce: true}
	if err := invalid.Validate(); err == nil {
		t.Fatal("incompatible GIF and view-once variants were accepted")
	}

	contacts := MessageSendPayload{To: "+5511999991234", Kind: MessageContact, Contacts: []ContactPayload{
		{DisplayName: "Primeiro", VCard: "BEGIN:VCARD\nEND:VCARD"},
		{DisplayName: "Segundo", VCard: "BEGIN:VCARD\nEND:VCARD"},
	}}
	if err := contacts.Validate(); err != nil {
		t.Fatalf("ordered contact array was rejected: %v", err)
	}
	secondDocument := MessageSendPayload{
		To: "+5511999991234", Kind: MessageDocument,
		Media: &MediaReference{Filename: "guia.pdf", MIMEType: "application/pdf"},
	}
	firstBatchItem := MessageBatchItemPayload{
		BatchID: "batch-0001", Position: 0, Size: 2,
		ProviderMessageID: "provider-batch-0001", Message: valid,
	}
	secondBatchItem := MessageBatchItemPayload{
		BatchID: "batch-0001", Position: 1, Size: 2,
		ProviderMessageID: "provider-batch-0002", Message: secondDocument,
	}
	batch := MessageBatchPayload{
		BatchID: "batch-0001", Size: 2, Items: []MessageBatchItemPayload{firstBatchItem, secondBatchItem},
	}
	if err := batch.Validate(); err != nil {
		t.Fatalf("ordered fallback batch was rejected: %v", err)
	}
	batch.Items = batch.Items[:1]
	if err := batch.Validate(); err == nil {
		t.Fatal("single-item batch was accepted")
	}
	batch.Items = []MessageBatchItemPayload{firstBatchItem, {
		BatchID: "batch-0001", Position: 1, Size: 2,
		ProviderMessageID: "provider-batch-0002", Message: contacts,
	}}
	if err := batch.Validate(); err == nil {
		t.Fatal("non-media batch child was accepted")
	}
	batch.Items = []MessageBatchItemPayload{firstBatchItem, secondBatchItem}
	batch.AlbumNative = true
	if err := batch.Validate(); err == nil {
		t.Fatal("unproven native album was accepted")
	}
}

func TestStructuredOutboundDTOsRejectConcurrentFieldsAndOversizeValues(t *testing.T) {
	t.Parallel()

	pollWithContacts := MessageSendPayload{
		To: "+5511999991234", Kind: MessagePoll,
		Poll:     &PollPayload{Name: "Escolha", Options: []string{"A", "B"}, SelectableOptions: 1},
		Contacts: []ContactPayload{{DisplayName: "Cliente", VCard: "BEGIN:VCARD\nEND:VCARD"}},
	}
	if err := pollWithContacts.Validate(); err == nil {
		t.Fatal("poll with concurrent contacts was accepted")
	}
	interactiveWithContacts := MessageSendPayload{
		To: "+5511999991234", Kind: MessageInteractive,
		Interactive: &InteractivePayload{Mode: "LIST", Options: []string{"A"}},
		Contacts:    []ContactPayload{{DisplayName: "Cliente", VCard: "BEGIN:VCARD\nEND:VCARD"}},
	}
	if err := interactiveWithContacts.Validate(); err == nil {
		t.Fatal("interactive message with concurrent contacts was accepted")
	}
	overlongContact := MessageSendPayload{To: "+5511999991234", Kind: MessageContact, Contact: &ContactPayload{
		DisplayName: string(make([]byte, maxContactDisplayNameByte+1)), VCard: "BEGIN:VCARD\nEND:VCARD",
	}}
	if err := overlongContact.Validate(); err == nil {
		t.Fatal("oversize contact was accepted")
	}
	overlongEvent := MessageSendPayload{To: "+5511999991234", Kind: MessageEvent, Event: &EventPayload{
		Title: string(make([]byte, maxEventTitleBytes+1)), StartAt: "2026-08-04T10:00:00-03:00", Timezone: "America/Sao_Paulo",
	}}
	if err := overlongEvent.Validate(); err == nil {
		t.Fatal("oversize event was accepted")
	}
	invalidEventOrder := MessageSendPayload{To: "+5511999991234", Kind: MessageEvent, Event: &EventPayload{
		Title: "Reunião", StartAt: "2026-08-04T10:00:00-03:00", EndAt: "2026-08-04T09:00:00-03:00", Timezone: "America/Sao_Paulo",
	}}
	if err := invalidEventOrder.Validate(); err == nil {
		t.Fatal("event ending before its start was accepted")
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

func TestStickerMaterializationPayloadIsBoundedAndSecretFree(t *testing.T) {
	t.Parallel()

	valid := Command{Type: CommandMaterializeSticker, Payload: json.RawMessage(
		`{"observation_id":"observe-ready-0001","expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/webp","max_bytes":1048576}`,
	)}
	if err := valid.ValidatePayload(); err != nil {
		t.Fatalf("valid sticker materialization payload was rejected: %v", err)
	}

	for name, payload := range map[string]string{
		"missing observation": `{"expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/webp","max_bytes":1024}`,
		"uppercase digest":    `{"observation_id":"observe-ready-0001","expected_sha256":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","expected_mime_type":"image/webp","max_bytes":1024}`,
		"wrong mime":          `{"observation_id":"observe-ready-0001","expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/png","max_bytes":1024}`,
		"oversize limit":      `{"observation_id":"observe-ready-0001","expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/webp","max_bytes":1048577}`,
		"zero bytes":          `{"observation_id":"observe-ready-0001","expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/webp","max_bytes":0}`,
		"media key leak":      `{"observation_id":"observe-ready-0001","expected_sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","expected_mime_type":"image/webp","max_bytes":1024,"media_key":"secret"}`,
	} {
		t.Run(name, func(t *testing.T) {
			command := Command{Type: CommandMaterializeSticker, Payload: json.RawMessage(payload)}
			if err := command.ValidatePayload(); err == nil {
				t.Fatal("invalid sticker materialization payload was accepted")
			}
		})
	}
}
