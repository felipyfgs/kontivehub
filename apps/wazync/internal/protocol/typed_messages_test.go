package protocol

import (
	"testing"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

func TestTypedMessageSenderBuildsEverySupportedOneToOneFamily(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	to := types.NewJID("5511999991234", types.DefaultUserServer)

	tests := []struct {
		name    string
		payload domain.MessageSendPayload
		assert  func(*testing.T)
	}{
		{
			name: "text preview and quote",
			payload: domain.MessageSendPayload{
				To: "+5511999991234", Kind: domain.MessageText, Text: "Veja https://example.test",
				ReplyTo: &domain.MessageReference{MessageID: "quoted-message-0001"},
				LinkPreview: &domain.LinkPreviewPayload{
					URL: "https://example.test", Title: "Exemplo", Description: "Descrição",
				},
			},
			assert: func(t *testing.T) {
				t.Helper()
				extended := client.message.GetExtendedTextMessage()
				if extended == nil || extended.GetTitle() != "Exemplo" ||
					extended.GetContextInfo().GetStanzaID() != "quoted-message-0001" ||
					extended.GetContextInfo().GetRemoteJID() != to.String() ||
					extended.GetContextInfo().GetParticipant() != "" {
					t.Fatalf("unexpected extended text: %+v", extended)
				}
			},
		},
		{
			name: "location",
			payload: domain.MessageSendPayload{
				To: "+5511999991234", Kind: domain.MessageLocation,
				Location: &domain.LocationPayload{Latitude: -23.55, Longitude: -46.63, Name: "São Paulo"},
			},
			assert: func(t *testing.T) {
				t.Helper()
				if location := client.message.GetLocationMessage(); location == nil || location.GetName() != "São Paulo" {
					t.Fatalf("unexpected location: %+v", location)
				}
			},
		},
		{
			name: "contact",
			payload: domain.MessageSendPayload{
				To: "+5511999991234", Kind: domain.MessageContact,
				Contact: &domain.ContactPayload{DisplayName: "Cliente", VCard: "BEGIN:VCARD\nEND:VCARD"},
			},
			assert: func(t *testing.T) {
				t.Helper()
				if contact := client.message.GetContactMessage(); contact == nil || contact.GetDisplayName() != "Cliente" {
					t.Fatalf("unexpected contact: %+v", contact)
				}
			},
		},
		{
			name: "poll",
			payload: domain.MessageSendPayload{
				To: "+5511999991234", Kind: domain.MessagePoll,
				Poll: &domain.PollPayload{Name: "Escolha", Options: []string{"A", "B"}, SelectableOptions: 1},
			},
			assert: func(t *testing.T) {
				t.Helper()
				if poll := client.message.GetPollCreationMessage(); poll == nil || len(poll.GetOptions()) != 2 {
					t.Fatalf("unexpected poll: %+v", poll)
				}
			},
		},
		{
			name: "interactive list",
			payload: domain.MessageSendPayload{
				To: "+5511999991234", Kind: domain.MessageInteractive,
				Interactive: &domain.InteractivePayload{
					Mode: " list ", Title: "Escolha", Options: []string{"Primeira", "Segunda"},
				},
			},
			assert: func(t *testing.T) {
				t.Helper()
				list := client.message.GetListMessage()
				if list == nil || len(list.GetSections()) != 1 || len(list.GetSections()[0].GetRows()) != 2 {
					t.Fatalf("unexpected interactive list: %+v", list)
				}
			},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			message, err := buildTypedMessage(client, to, test.payload, nil)
			if err != nil {
				t.Fatalf("build typed message: %v", err)
			}
			client.message = message
			test.assert(t)
		})
	}
}

func TestInteractiveBuilderRejectsButtonsAndOptionsOutsideListLimit(t *testing.T) {
	t.Parallel()

	if message, err := buildInteractiveMessage(&domain.InteractivePayload{
		Mode: "BUTTONS", Options: []string{"Confirmar"},
	}, nil); err == nil || err.Error() != "interactive mode must be LIST" || message != nil {
		t.Fatalf("BUTTONS must fail closed: message=%+v err=%v", message, err)
	}

	tooMany := make([]string, maxInteractiveItems+1)
	for index := range tooMany {
		tooMany[index] = "Opção"
	}
	if message, err := buildInteractiveMessage(&domain.InteractivePayload{
		Mode: "LIST", Options: tooMany,
	}, nil); err == nil || err.Error() != "interactive message requires 1 to 10 options" || message != nil {
		t.Fatalf("LIST above the real limit must fail closed: message=%+v err=%v", message, err)
	}
}

func TestTypedMessageSenderUsesRemoteParticipantOnlyWhenQuotedSenderIsKnown(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	to := types.NewJID("5511999991234", types.DefaultUserServer)
	message, err := buildTypedMessage(client, to, domain.MessageSendPayload{
		To: "+5511999991234", Kind: domain.MessageText, Text: "Resposta",
		ReplyTo: &domain.MessageReference{
			MessageID: "quoted-inbound-0001", Sender: "+5511999991234",
		},
	}, nil)
	if err != nil {
		t.Fatalf("build quoted inbound message: %v", err)
	}
	contextInfo := message.GetExtendedTextMessage().GetContextInfo()
	if contextInfo.GetStanzaID() != "quoted-inbound-0001" ||
		contextInfo.GetParticipant() != to.String() || contextInfo.GetRemoteJID() != to.String() {
		t.Fatalf("unexpected quoted context: %+v", contextInfo)
	}
}

func TestTypedMessageBuilderRejectsMissingKind(t *testing.T) {
	t.Parallel()

	message, err := buildTypedMessage(
		&fakeClient{connected: true},
		types.NewJID("5511999991234", types.DefaultUserServer),
		domain.MessageSendPayload{
			To:   "+5511999991234",
			Text: "Mensagem sem tipo explícito",
		},
		nil,
	)
	if err == nil || message != nil {
		t.Fatalf("message without an explicit kind must fail closed: message=%+v err=%v", message, err)
	}
}

func TestTypedMessageSenderStreamsMediaAndKeepsProviderID(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	adapter := NewWhatsMeowAdapter(&fakeResolver{client: client})
	payload := domain.MessageSendPayload{
		To: "+5511999991234", Kind: domain.MessageAudio,
		Media: &domain.MediaReference{
			Filename: "audio.ogg", MIMEType: "audio/ogg", SizeBytes: 5,
			SHA256: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", PTT: true,
		},
	}
	if err := adapter.SendTypedMessage(
		t.Context(), "session-typed-0001", payload, "provider-typed-0001", []byte("audio"),
	); err != nil {
		t.Fatalf("send typed audio: %v", err)
	}
	if !client.streamed || client.uploadType != whatsmeow.MediaAudio {
		t.Fatalf("media was not streamed as audio: streamed=%v type=%q", client.streamed, client.uploadType)
	}
	if audio := client.message.GetAudioMessage(); audio == nil || !audio.GetPTT() {
		t.Fatalf("PTT flag was not preserved: %+v", audio)
	}
	if client.extra.ID != "provider-typed-0001" {
		t.Fatalf("provider ID changed: %s", client.extra.ID)
	}
}

func TestTypedMessageBuilderRejectsMIMEKindMismatchAndOversizedPoll(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	to := types.NewJID("5511999991234", types.DefaultUserServer)
	upload := &whatsmeow.UploadResponse{}
	sticker, err := buildTypedMessage(client, to, domain.MessageSendPayload{
		Kind: domain.MessageSticker, Media: &domain.MediaReference{MIMEType: "image/webp"},
	}, upload)
	if err != nil || sticker.GetStickerMessage() == nil {
		t.Fatalf("valid WebP sticker was rejected: message=%+v err=%v", sticker, err)
	}

	if _, err := buildTypedMessage(client, to, domain.MessageSendPayload{
		Kind: domain.MessageSticker, Media: &domain.MediaReference{MIMEType: "image/png"},
	}, upload); err == nil {
		t.Fatal("non-WebP sticker was accepted")
	}
	if _, err := buildTypedMessage(client, to, domain.MessageSendPayload{
		Kind: domain.MessageImage, Media: &domain.MediaReference{MIMEType: "image/webp", PTT: true},
	}, upload); err == nil {
		t.Fatal("PTT flag on a non-audio message was accepted")
	}
	options := make([]string, 13)
	for index := range options {
		options[index] = "option"
	}
	if _, err := buildTypedMessage(client, to, domain.MessageSendPayload{
		Kind: domain.MessagePoll,
		Poll: &domain.PollPayload{Name: "too many", Options: options, SelectableOptions: 1},
	}, nil); err == nil {
		t.Fatal("poll with more than 12 options was accepted")
	}
}
