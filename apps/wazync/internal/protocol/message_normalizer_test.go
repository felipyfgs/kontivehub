package protocol

import (
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

func TestCatalogedNormalizerCoversCommonSemanticFamilies(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name, provider, kind string
		message              *waE2E.Message
		assert               func(*testing.T, map[string]any)
	}{
		{
			name: "link preview", provider: "extendedTextMessage", kind: "TEXT",
			message: &waE2E.Message{ExtendedTextMessage: &waE2E.ExtendedTextMessage{
				Text: proto.String("Veja"), MatchedText: proto.String("https://example.test/item"),
				Title: proto.String("Título"), Description: proto.String("Descrição"),
				JPEGThumbnail: []byte("raw-thumbnail"), MediaKey: []byte("media-key"),
				ThumbnailDirectPath: proto.String("/must-not-leak"),
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				preview := content["link_preview"].(map[string]any)
				if preview["url"] != "https://example.test/item" || preview["title"] != "Título" {
					t.Fatalf("link preview semantics lost: %+v", content)
				}
			},
		},
		{
			name: "ptt", provider: "audioMessage", kind: "AUDIO",
			message: &waE2E.Message{AudioMessage: &waE2E.AudioMessage{
				PTT: proto.Bool(true), Seconds: proto.Uint32(12),
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				if content["ptt"] != true || content["duration_seconds"] != uint32(12) {
					t.Fatalf("PTT semantics lost: %+v", content)
				}
			},
		},
		{
			name: "gif", provider: "videoMessage", kind: "VIDEO",
			message: &waE2E.Message{VideoMessage: &waE2E.VideoMessage{
				GifPlayback: proto.Bool(true), Caption: proto.String("animação"),
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				if content["gif"] != true || content["caption"] != "animação" {
					t.Fatalf("GIF semantics lost: %+v", content)
				}
			},
		},
		{
			name: "live location", provider: "liveLocationMessage", kind: "LOCATION",
			message: &waE2E.Message{LiveLocationMessage: &waE2E.LiveLocationMessage{
				DegreesLatitude: proto.Float64(-23.5), DegreesLongitude: proto.Float64(-46.6),
				Caption: proto.String("Em deslocamento"), SequenceNumber: proto.Int64(9),
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				location := content["location"].(map[string]any)
				if location["live"] != true || location["sequence"] != int64(9) {
					t.Fatalf("live location semantics lost: %+v", content)
				}
			},
		},
		{
			name: "contacts array", provider: "contactsArrayMessage", kind: "CONTACT",
			message: &waE2E.Message{ContactsArrayMessage: &waE2E.ContactsArrayMessage{
				Contacts: []*waE2E.ContactMessage{
					{DisplayName: proto.String("Um"), Vcard: proto.String("BEGIN:VCARD\nFN:Um\nEND:VCARD")},
					{DisplayName: proto.String("Dois"), Vcard: proto.String("BEGIN:VCARD\nFN:Dois\nEND:VCARD")},
				},
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				if contacts := content["contacts"].([]map[string]any); len(contacts) != 2 {
					t.Fatalf("contacts array semantics lost: %+v", content)
				}
			},
		},
		{
			name: "button response", provider: "buttonsResponseMessage", kind: "INTERACTIVE",
			message: &waE2E.Message{ButtonsResponseMessage: &waE2E.ButtonsResponseMessage{
				SelectedButtonID: proto.String("confirmar"),
				Response: &waE2E.ButtonsResponseMessage_SelectedDisplayText{
					SelectedDisplayText: "Confirmar",
				},
			}},
			assert: func(t *testing.T, content map[string]any) {
				t.Helper()
				interactive := content["interactive"].(map[string]any)
				if interactive["selected_id"] != "confirmar" || interactive["display_text"] != "Confirmar" {
					t.Fatalf("interactive response semantics lost: %+v", content)
				}
			},
		},
	}
	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			normalized := normalizeCatalogedMessage(test.message)
			if normalized.ProviderType != test.provider || string(normalized.Kind) != test.kind {
				t.Fatalf("unexpected classification: %+v", normalized)
			}
			test.assert(t, normalized.Content)
			assertNormalizedPayloadSanitized(t, normalizedMessagePayload(normalized))
		})
	}
}

func TestCatalogedNormalizerHasNoTextFallbackAndRejectsAmbiguity(t *testing.T) {
	t.Parallel()

	unknown := normalizeCatalogedMessage(&waE2E.Message{Chat: &waE2E.Chat{}})
	if unknown.Kind != domain.MessageUnsupported || unknown.ProviderType != "chat" {
		t.Fatalf("known unsupported field was coerced: %+v", unknown)
	}
	if _, exists := unknown.Content["text"]; exists {
		t.Fatalf("unsupported message acquired artificial text: %+v", unknown)
	}

	ambiguous := normalizeCatalogedMessage(&waE2E.Message{
		Conversation: proto.String("must not win"),
		ImageMessage: &waE2E.ImageMessage{Caption: proto.String("must not win either")},
	})
	if ambiguous.Kind != domain.MessageUnsupported || ambiguous.ProviderType != "ambiguous" {
		t.Fatalf("ambiguous variants were not rejected: %+v", ambiguous)
	}
	if _, exists := ambiguous.Content["text"]; exists {
		t.Fatalf("ambiguous message acquired artificial text: %+v", ambiguous)
	}
}

func TestCatalogedNormalizerAllowsOnlyKnownAncillaryCombination(t *testing.T) {
	t.Parallel()

	legitimate := normalizeCatalogedMessage(&waE2E.Message{
		Conversation: proto.String("conteúdo"),
		MessageContextInfo: &waE2E.MessageContextInfo{
			MessageSecret: []byte("must-never-leak"),
		},
	})
	if legitimate.Kind != domain.MessageText || legitimate.ProviderType != "conversation" ||
		legitimate.Content["text"] != "conteúdo" {
		t.Fatalf("legitimate ancillary combination was rejected: %+v", legitimate)
	}
	assertNormalizedPayloadSanitized(t, normalizedMessagePayload(legitimate))

	ambiguous := normalizeCatalogedMessage(&waE2E.Message{
		Conversation:       proto.String("one"),
		ImageMessage:       &waE2E.ImageMessage{Caption: proto.String("two")},
		MessageContextInfo: &waE2E.MessageContextInfo{MessageSecret: []byte("secret")},
	})
	if ambiguous.Kind != domain.MessageUnsupported || ambiguous.ProviderType != "ambiguous" {
		t.Fatalf("ancillary field hid incompatible semantic variants: %+v", ambiguous)
	}
}

func TestCatalogedNormalizerUnwrapsLiveAndHistoryIdentically(t *testing.T) {
	t.Parallel()

	message := &waE2E.Message{EphemeralMessage: &waE2E.FutureProofMessage{
		Message: &waE2E.Message{ViewOnceMessageV2: &waE2E.FutureProofMessage{
			Message: &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
				Caption: proto.String("mesmo conteúdo"), MediaKey: []byte("secret"),
				DirectPath: proto.String("/secret-path"),
			}},
		}},
	}}
	live := normalizedMessageContent(message)
	chat := types.NewJID("5511999991234", types.DefaultUserServer)
	peer, err := NormalizeOneToOneJID(chat)
	if err != nil {
		t.Fatalf("normalize peer: %v", err)
	}
	history := normalizedHistoryMessage(&events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{Chat: chat, Sender: chat},
			ID:            "provider-parity", Timestamp: time.Unix(1_700_000_000, 0),
		},
		Message: message,
	}, peer)
	delete(history, "provider_message_id")
	delete(history, "from")
	delete(history, "direction")
	delete(history, "history")
	delete(history, "occurred_at")
	liveJSON, _ := json.Marshal(live)
	historyJSON, _ := json.Marshal(history)
	if string(liveJSON) != string(historyJSON) {
		t.Fatalf("live/history classification diverged\nlive=%s\nhistory=%s", liveJSON, historyJSON)
	}
	assertNormalizedPayloadSanitized(t, live)
}

func assertNormalizedPayloadSanitized(t *testing.T, payload map[string]any) {
	t.Helper()
	encoded, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("marshal normalized payload: %v", err)
	}
	lower := strings.ToLower(string(encoded))
	for _, forbidden := range []string{
		"mediakey", "media_key", "directpath", "direct_path", "protobuf", "ciphertext",
		"thumbnail_base64", "raw", "jid", "device", "node", "secret-path", "raw-thumbnail",
	} {
		if strings.Contains(lower, forbidden) {
			t.Fatalf("normalized payload leaked %q: %s", forbidden, encoded)
		}
	}
}
