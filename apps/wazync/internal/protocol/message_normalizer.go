package protocol

import (
	"fmt"
	"net/url"
	"path/filepath"
	"strings"
	"time"
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
	message, wrapperFlags := unwrapCatalogedMessage(message, 0, nil)
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
	if entry.Disposition == catalog.MessageControl {
		return normalizedMessage{Kind: domain.MessageUnsupported, ProviderType: entry.ProviderType, Family: "CONTROL"}
	}
	if entry.Disposition == catalog.MessageAction {
		return normalizedMessage{Kind: domain.MessageUnsupported, ProviderType: entry.ProviderType, Family: entry.Family}
	}
	if entry.Disposition == catalog.MessageOutOfScope {
		return normalizedMessage{Kind: domain.MessageUnsupported, ProviderType: entry.ProviderType, Family: "OUT_OF_SCOPE"}
	}
	if entry.Disposition != catalog.MessageProjected {
		content := map[string]any{"content_present": true}
		if strings.HasPrefix(entry.ProviderType, "viewOnceMessage") {
			content["content_present"] = false
			content["view_once"] = true
		}
		return unsupportedMessage(entry.ProviderType, entry.Family, content)
	}
	result := normalizedMessage{
		Kind: domain.MessageKind(entry.Family), ProviderType: entry.ProviderType,
		Family: entry.Family, Content: make(map[string]any), Message: message,
		Context: inboundMessageContext(message),
	}
	for key, value := range wrapperFlags {
		result.Content[key] = value
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

func unwrapCatalogedMessage(message *waE2E.Message, depth int, flags map[string]any) (*waE2E.Message, map[string]any) {
	if message == nil || depth >= 8 {
		return message, flags
	}
	if flags == nil {
		flags = make(map[string]any)
	}
	var nested *waE2E.Message
	switch {
	case message.GetEphemeralMessage() != nil:
		nested = message.GetEphemeralMessage().GetMessage()
		flags["ephemeral"] = true
	case message.GetDocumentWithCaptionMessage() != nil:
		nested = message.GetDocumentWithCaptionMessage().GetMessage()
	case message.GetEditedMessage() != nil:
		nested = message.GetEditedMessage().GetMessage()
	case message.GetDeviceSentMessage() != nil:
		nested = message.GetDeviceSentMessage().GetMessage()
	case message.GetAssociatedChildMessage() != nil:
		nested = message.GetAssociatedChildMessage().GetMessage()
	case message.GetPollCreationMessageV4() != nil:
		nested = message.GetPollCreationMessageV4().GetMessage()
	}
	if nested == nil {
		return message, flags
	}
	return unwrapCatalogedMessage(nested, depth+1, flags)
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
	case "productMessage":
		product := message.GetProductMessage()
		snapshot := product.GetProduct()
		facts := richFacts()
		facts.add("Moeda", snapshot.GetCurrencyCode())
		facts.add("Preço", amount1000(snapshot.GetCurrencyCode(), snapshot.GetPriceAmount1000()))
		facts.addCount("Imagens", int64(snapshot.GetProductImageCount()))
		addRichCard(content, "PRODUCT", firstText(snapshot.GetTitle(), "Produto compartilhado"),
			firstText(snapshot.GetDescription(), product.GetBody()), facts)
	case "orderMessage":
		order := message.GetOrderMessage()
		facts := richFacts()
		facts.addCount("Itens", int64(order.GetItemCount()))
		facts.add("Status", order.GetStatus().String())
		facts.add("Total", amount1000(order.GetTotalCurrencyCode(), order.GetTotalAmount1000()))
		addRichCard(content, "ORDER", firstText(order.GetOrderTitle(), "Pedido compartilhado"), order.GetMessage(), facts)
	case "invoiceMessage":
		invoice := message.GetInvoiceMessage()
		facts := richFacts()
		facts.add("Anexo", invoice.GetAttachmentType().String())
		addRichCard(content, "PAYMENT", "Fatura compartilhada", invoice.GetNote(), facts)
	case "requestPaymentMessage":
		payment := message.GetRequestPaymentMessage()
		facts := richFacts()
		facts.add("Valor", amount1000(payment.GetCurrencyCodeIso4217(), int64(payment.GetAmount1000())))
		facts.addTime("Expira em", payment.GetExpiryTimestamp(), false)
		addRichCard(content, "PAYMENT", "Solicitação de pagamento", "Conteúdo somente leitura", facts)
	case "sendPaymentMessage":
		addRichCard(content, "PAYMENT", "Pagamento compartilhado", "Conteúdo somente leitura", richFacts())
	case "declinePaymentRequestMessage":
		addRichCard(content, "PAYMENT", "Pagamento recusado", "Conteúdo somente leitura", richFacts())
	case "cancelPaymentRequestMessage":
		addRichCard(content, "PAYMENT", "Solicitação de pagamento cancelada", "Conteúdo somente leitura", richFacts())
	case "paymentInviteMessage":
		invite := message.GetPaymentInviteMessage()
		facts := richFacts()
		facts.add("Serviço", invite.GetServiceType().String())
		facts.add("Tipo", invite.GetInviteType().String())
		facts.addTime("Expira em", invite.GetExpiryTimestamp(), false)
		addRichCard(content, "PAYMENT", "Convite de pagamento", "Conteúdo somente leitura", facts)
	case "paymentReminderMessage":
		reminder := message.GetPaymentReminderMessage()
		facts := richFacts()
		facts.add("Frequência", reminder.GetFrequency().String())
		facts.add("Status", reminder.GetStatus().String())
		facts.add("Valor", semanticMoney(reminder.GetAmount()))
		addRichCard(content, "PAYMENT", "Lembrete de pagamento", reminder.GetDescription(), facts)
	case "splitPaymentMessage":
		split := message.GetSplitPaymentMessage()
		facts := richFacts()
		facts.add("Valor", semanticMoney(split.GetTotalAmount()))
		facts.addCount("Participantes", int64(len(split.GetParticipants())))
		facts.addTime("Criado em", split.GetCreatedAtMS(), true)
		addRichCard(content, "PAYMENT", "Divisão de pagamento", split.GetDescription(), facts)
	case "splitPaymentUpdateMessage":
		addRichCard(content, "PAYMENT", "Atualização de divisão de pagamento", "Conteúdo somente leitura", richFacts())
	case "groupInviteMessage":
		invite := message.GetGroupInviteMessage()
		facts := richFacts()
		facts.add("Tipo", invite.GetGroupType().String())
		facts.addTime("Expira em", invite.GetInviteExpiration(), false)
		addRichCard(content, "INVITE", firstText(invite.GetGroupName(), "Convite para grupo"), invite.GetCaption(), facts)
	case "eventMessage":
		event := message.GetEventMessage()
		facts := richFacts()
		facts.addTime("Início", event.GetStartTime(), false)
		facts.addTime("Fim", event.GetEndTime(), false)
		facts.addBool("Cancelado", event.GetIsCanceled())
		if event.GetLocation() != nil {
			facts.add("Local", event.GetLocation().GetName())
		}
		addRichCard(content, "EVENT", firstText(event.GetName(), "Evento compartilhado"), event.GetDescription(), facts)
	case "eventInviteMessage":
		invite := message.GetEventInviteMessage()
		facts := richFacts()
		facts.addTime("Início", invite.GetStartTime(), false)
		facts.addTime("Fim", invite.GetEndTime(), false)
		facts.addBool("Cancelado", invite.GetIsCanceled())
		addRichCard(content, "EVENT", firstText(invite.GetEventTitle(), "Convite para evento"), invite.GetCaption(), facts)
	case "scheduledCallCreationMessage":
		call := message.GetScheduledCallCreationMessage()
		facts := richFacts()
		facts.add("Tipo", call.GetCallType().String())
		facts.addTime("Agendada para", call.GetScheduledTimestampMS(), true)
		addRichCard(content, "CALL", firstText(call.GetTitle(), "Chamada agendada"), "Conteúdo somente leitura", facts)
	case "scheduledCallEditMessage":
		facts := richFacts()
		facts.add("Alteração", message.GetScheduledCallEditMessage().GetEditType().String())
		addRichCard(content, "CALL", "Chamada agendada atualizada", "Conteúdo somente leitura", facts)
	case "callLogMesssage":
		call := message.GetCallLogMesssage()
		facts := richFacts()
		facts.add("Tipo", call.GetCallType().String())
		facts.add("Resultado", call.GetCallOutcome().String())
		facts.addCount("Duração (s)", call.GetDurationSecs())
		facts.addCount("Participantes", int64(len(call.GetParticipants())))
		addRichCard(content, "CALL", "Registro de chamada", "Conteúdo somente leitura", facts)
	case "call":
		call := message.GetCall()
		facts := richFacts()
		facts.addCount("Ponto de entrada", int64(call.GetCallEntryPoint()))
		addRichCard(content, "CALL", "Chamada do WhatsApp", call.GetCallReason(), facts)
	}
}

type richCardFacts []map[string]string

func richFacts() *richCardFacts {
	facts := richCardFacts{}
	return &facts
}

func (facts *richCardFacts) add(label, value string) {
	label = boundedText(strings.TrimSpace(label), 64)
	value = boundedText(strings.TrimSpace(value), 1024)
	if label == "" || value == "" || len(*facts) >= 12 {
		return
	}
	*facts = append(*facts, map[string]string{"label": label, "value": value})
}

func (facts *richCardFacts) addCount(label string, value int64) {
	if value > 0 {
		facts.add(label, fmt.Sprintf("%d", value))
	}
}

func (facts *richCardFacts) addBool(label string, value bool) {
	if value {
		facts.add(label, "Sim")
	}
}

func (facts *richCardFacts) addTime(label string, value int64, milliseconds bool) {
	if value <= 0 {
		return
	}
	if milliseconds {
		value /= 1000
	}
	if value <= 0 || value > 253402300799 {
		return
	}
	facts.add(label, time.Unix(value, 0).UTC().Format(time.RFC3339))
}

func addRichCard(content map[string]any, category, title, description string, facts *richCardFacts) {
	card := map[string]any{
		"category": category,
		"title":    boundedText(firstText(title, "Conteúdo do WhatsApp"), 4096),
	}
	if description = boundedText(strings.TrimSpace(description), 8192); description != "" {
		card["description"] = description
	}
	if facts != nil && len(*facts) > 0 {
		card["facts"] = *facts
	}
	content["rich_card"] = card
}

func firstText(values ...string) string {
	for _, value := range values {
		if value = strings.TrimSpace(value); value != "" {
			return value
		}
	}
	return ""
}

func amount1000(currency string, value int64) string {
	if value == 0 {
		return ""
	}
	currency = boundedText(strings.ToUpper(strings.TrimSpace(currency)), 12)
	amount := fmt.Sprintf("%.3f", float64(value)/1000)
	return strings.TrimSpace(currency + " " + amount)
}

func semanticMoney(money *waE2E.Money) string {
	if money == nil || money.GetValue() == 0 {
		return ""
	}
	offset := money.GetOffset()
	if offset > 6 {
		offset = 6
	}
	divisor := int64(1)
	for range offset {
		divisor *= 10
	}
	format := fmt.Sprintf("%%.%df", offset)
	return strings.TrimSpace(strings.ToUpper(money.GetCurrencyCode()) + " " + fmt.Sprintf(format, float64(money.GetValue())/float64(divisor)))
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
