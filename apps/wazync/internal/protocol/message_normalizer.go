package protocol

import (
	"net/url"
	"path/filepath"
	"strings"
	"unicode/utf8"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol/catalog"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"google.golang.org/protobuf/reflect/protoreflect"
)

const (
	maxSemanticTextBytes = 64 << 10
	maxContacts          = 50
	maxVCardBytes        = 64 << 10
)

type normalizedMessage struct {
	Kind         domain.MessageKind
	ProviderType string
	Family       string
	Content      map[string]any
	Message      *waE2E.Message
	Context      *waE2E.ContextInfo
}

func normalizeCatalogedMessage(message *waE2E.Message) normalizedMessage {
	message = unwrapCatalogedMessage(message, 0)
	if message == nil {
		return unsupportedMessage("empty", "UNSUPPORTED", nil)
	}
	active := activeMessageFields(message)
	if len(active) != 1 {
		variants := make([]string, 0, len(active))
		for _, field := range active {
			variants = append(variants, string(field.Name()))
		}
		return unsupportedMessage("ambiguous", "UNSUPPORTED", map[string]any{
			"content_present": len(active) > 0, "variants": variants,
		})
	}
	field := active[0]
	entry, ok := catalog.MessageFields[field.Number()]
	if !ok || entry.Name != field.Name() {
		return unsupportedMessage(string(field.Name()), "UNSUPPORTED", nil)
	}
	if entry.Disposition != catalog.MessageProjected {
		return unsupportedMessage(entry.ProviderType, entry.Family, map[string]any{"content_present": true})
	}
	result := normalizedMessage{
		Kind: domain.MessageKind(entry.Family), ProviderType: entry.ProviderType,
		Family: entry.Family, Content: make(map[string]any), Message: message,
		Context: inboundMessageContext(message),
	}
	extractCatalogedContent(&result)
	return result
}

func activeMessageFields(message *waE2E.Message) []protoreflect.FieldDescriptor {
	reflected := message.ProtoReflect()
	fields := reflected.Descriptor().Fields()
	active := make([]protoreflect.FieldDescriptor, 0, 2)
	for index := 0; index < fields.Len(); index++ {
		field := fields.Get(index)
		if reflected.Has(field) {
			active = append(active, field)
		}
	}
	if len(active) > 1 {
		filtered := active[:0]
		for _, field := range active {
			// messageContextInfo is an ancillary secret/reporting container
			// that whatsmeow legitimately co-locates with one semantic field.
			// It is consumed internally and never projected.
			if field.Name() != "messageContextInfo" {
				filtered = append(filtered, field)
			}
		}
		active = filtered
	}
	return active
}

func unwrapCatalogedMessage(message *waE2E.Message, depth int) *waE2E.Message {
	if message == nil || depth >= 8 {
		return message
	}
	var nested *waE2E.Message
	switch {
	case message.GetViewOnceMessage() != nil:
		nested = message.GetViewOnceMessage().GetMessage()
	case message.GetEphemeralMessage() != nil:
		nested = message.GetEphemeralMessage().GetMessage()
	case message.GetViewOnceMessageV2() != nil:
		nested = message.GetViewOnceMessageV2().GetMessage()
	case message.GetViewOnceMessageV2Extension() != nil:
		nested = message.GetViewOnceMessageV2Extension().GetMessage()
	case message.GetDocumentWithCaptionMessage() != nil:
		nested = message.GetDocumentWithCaptionMessage().GetMessage()
	case message.GetEditedMessage() != nil:
		nested = message.GetEditedMessage().GetMessage()
	case message.GetDeviceSentMessage() != nil:
		nested = message.GetDeviceSentMessage().GetMessage()
	}
	if nested == nil {
		return message
	}
	return unwrapCatalogedMessage(nested, depth+1)
}

func unsupportedMessage(providerType, family string, content map[string]any) normalizedMessage {
	if content == nil {
		content = map[string]any{"content_present": true}
	}
	return normalizedMessage{
		Kind: domain.MessageUnsupported, ProviderType: providerType,
		Family: family, Content: content,
	}
}

func extractCatalogedContent(result *normalizedMessage) {
	message, content := result.Message, result.Content
	switch result.ProviderType {
	case "conversation":
		content["text"] = boundedText(message.GetConversation(), maxSemanticTextBytes)
	case "extendedTextMessage":
		extended := message.GetExtendedTextMessage()
		content["text"] = boundedText(extended.GetText(), maxSemanticTextBytes)
		if preview := semanticLinkPreview(extended); preview != nil {
			content["link_preview"] = preview
		}
	case "imageMessage":
		addMediaContent(content, message.GetImageMessage().GetCaption(), "", false, false)
	case "audioMessage":
		audio := message.GetAudioMessage()
		addMediaContent(content, "", "", audio.GetPTT(), false)
		content["duration_seconds"] = audio.GetSeconds()
	case "videoMessage", "ptvMessage":
		video := message.GetVideoMessage()
		if result.ProviderType == "ptvMessage" {
			video = message.GetPtvMessage()
		}
		addMediaContent(content, video.GetCaption(), "", false, video.GetGifPlayback())
		content["duration_seconds"] = video.GetSeconds()
	case "documentMessage":
		document := message.GetDocumentMessage()
		addMediaContent(content, document.GetCaption(), sanitizeFilename(document.GetFileName()), false, false)
	case "stickerMessage":
		content["animated"] = message.GetStickerMessage().GetIsAnimated()
	case "locationMessage":
		location := message.GetLocationMessage()
		content["location"] = map[string]any{
			"latitude": location.GetDegreesLatitude(), "longitude": location.GetDegreesLongitude(),
			"name": boundedText(location.GetName(), 1024), "address": boundedText(location.GetAddress(), 4096),
			"caption": boundedText(location.GetComment(), 4096), "live": false,
		}
	case "liveLocationMessage":
		location := message.GetLiveLocationMessage()
		content["location"] = map[string]any{
			"latitude": location.GetDegreesLatitude(), "longitude": location.GetDegreesLongitude(),
			"caption": boundedText(location.GetCaption(), 4096), "live": true,
			"accuracy_meters": location.GetAccuracyInMeters(), "sequence": location.GetSequenceNumber(),
		}
	case "contactMessage":
		content["contacts"] = []map[string]any{semanticContact(message.GetContactMessage())}
	case "contactsArrayMessage":
		contacts := message.GetContactsArrayMessage().GetContacts()
		if len(contacts) > maxContacts {
			contacts = contacts[:maxContacts]
		}
		projected := make([]map[string]any, 0, len(contacts))
		for _, contact := range contacts {
			if contact != nil {
				projected = append(projected, semanticContact(contact))
			}
		}
		content["contacts"] = projected
	case "pollCreationMessage", "pollCreationMessageV2", "pollCreationMessageV3",
		"pollCreationMessageV5", "pollCreationMessageV6":
		addPoll(content, pollCreation(message))
	case "buttonsResponseMessage":
		response := message.GetButtonsResponseMessage()
		content["interactive"] = map[string]any{
			"mode": "BUTTON_RESPONSE", "selected_id": boundedText(response.GetSelectedButtonID(), 1024),
			"display_text": boundedText(response.GetSelectedDisplayText(), 4096),
		}
	case "listResponseMessage":
		response := message.GetListResponseMessage()
		content["interactive"] = map[string]any{
			"mode":         "LIST_RESPONSE",
			"selected_id":  boundedText(response.GetSingleSelectReply().GetSelectedRowID(), 1024),
			"display_text": boundedText(response.GetTitle(), 4096),
			"description":  boundedText(response.GetDescription(), 4096),
		}
	case "templateButtonReplyMessage":
		response := message.GetTemplateButtonReplyMessage()
		content["interactive"] = map[string]any{
			"mode": "TEMPLATE_BUTTON_RESPONSE", "selected_id": boundedText(response.GetSelectedID(), 1024),
			"display_text": boundedText(response.GetSelectedDisplayText(), 4096),
		}
	case "interactiveResponseMessage":
		response := message.GetInteractiveResponseMessage()
		content["interactive"] = map[string]any{
			"mode":         "NATIVE_FLOW_RESPONSE",
			"name":         boundedText(response.GetNativeFlowResponseMessage().GetName(), 1024),
			"display_text": boundedText(response.GetBody().GetText(), 4096),
		}
	case "buttonsMessage":
		content["interactive"] = map[string]any{
			"mode": "BUTTONS", "title": boundedText(message.GetButtonsMessage().GetContentText(), 4096),
			"description": boundedText(message.GetButtonsMessage().GetFooterText(), 4096),
		}
	case "listMessage":
		content["interactive"] = map[string]any{
			"mode": "LIST", "title": boundedText(message.GetListMessage().GetTitle(), 4096),
			"description": boundedText(message.GetListMessage().GetDescription(), 4096),
		}
	case "interactiveMessage":
		content["interactive"] = map[string]any{
			"mode": "NATIVE_FLOW", "title": boundedText(message.GetInteractiveMessage().GetHeader().GetTitle(), 4096),
			"description": boundedText(message.GetInteractiveMessage().GetBody().GetText(), 4096),
		}
	case "templateMessage":
		content["interactive"] = map[string]any{"mode": "TEMPLATE"}
	}
}

func semanticLinkPreview(message *waE2E.ExtendedTextMessage) map[string]any {
	if message == nil {
		return nil
	}
	rawURL := strings.TrimSpace(message.GetMatchedText())
	parsed, err := url.Parse(rawURL)
	if err != nil || (parsed.Scheme != "http" && parsed.Scheme != "https") || parsed.Host == "" {
		return nil
	}
	return map[string]any{
		"url": rawURL, "title": boundedText(message.GetTitle(), 4096),
		"description": boundedText(message.GetDescription(), 8192),
	}
}

func semanticContact(contact *waE2E.ContactMessage) map[string]any {
	return map[string]any{
		"display_name": boundedText(contact.GetDisplayName(), 1024),
		"vcard":        boundedText(contact.GetVcard(), maxVCardBytes),
	}
}

func addMediaContent(content map[string]any, caption, filename string, ptt, gif bool) {
	if caption = boundedText(caption, maxSemanticTextBytes); caption != "" {
		content["caption"] = caption
	}
	if filename != "" {
		content["filename"] = filename
	}
	if ptt {
		content["ptt"] = true
	}
	if gif {
		content["gif"] = true
	}
}

func boundedText(value string, limit int) string {
	value = strings.ToValidUTF8(value, "")
	if len(value) <= limit {
		return value
	}
	value = value[:limit]
	for !utf8.ValidString(value) {
		value = value[:len(value)-1]
	}
	return value
}

func sanitizeFilename(value string) string {
	value = filepath.Base(strings.ReplaceAll(value, "\\", "/"))
	value = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return -1
		}
		return r
	}, value)
	return boundedText(strings.TrimSpace(value), 255)
}
