package catalog

import (
	"fmt"

	"google.golang.org/protobuf/reflect/protoreflect"
)

const MessageDescriptorFullName = "WAWebProtobufsE2E.Message"

const (
	MessageProjected   Disposition = "PROJECTED"
	MessageAction      Disposition = "ACTION"
	MessageControl     Disposition = "CONTROL"
	MessageOutOfScope  Disposition = "OUT_OF_SCOPE"
	MessageUnsupported Disposition = "UNSUPPORTED"
)

// MessageEntry is the closed decision for one field in the pinned waE2E.Message
// descriptor. Number and Name intentionally duplicate the upstream descriptor:
// the reflective test fails before an upgrade can silently acquire a default.
type MessageEntry struct {
	Number            protoreflect.FieldNumber
	Name              protoreflect.Name
	ProviderType      string
	Family            string
	Disposition       Disposition
	Extractor         string
	OutboundSupported bool
	Source            string
	Owner             string
	Evidence          string
	Reference         string
}

// MessageFields is keyed by protobuf field number because field numbers are the
// stable wire identity. Names are pinned too and checked reflectively.
var MessageFields = buildMessageFields()

func buildMessageFields() map[protoreflect.FieldNumber]MessageEntry {
	// This is deliberately a static pin, not data derived from ProtoReflect.
	pinned := []struct {
		number protoreflect.FieldNumber
		name   protoreflect.Name
	}{
		{1, "conversation"}, {2, "senderKeyDistributionMessage"}, {3, "imageMessage"},
		{4, "contactMessage"}, {5, "locationMessage"}, {6, "extendedTextMessage"},
		{7, "documentMessage"}, {8, "audioMessage"}, {9, "videoMessage"}, {10, "call"},
		{11, "chat"}, {12, "protocolMessage"}, {13, "contactsArrayMessage"},
		{14, "highlyStructuredMessage"}, {15, "fastRatchetKeySenderKeyDistributionMessage"},
		{16, "sendPaymentMessage"}, {18, "liveLocationMessage"}, {22, "requestPaymentMessage"},
		{23, "declinePaymentRequestMessage"}, {24, "cancelPaymentRequestMessage"},
		{25, "templateMessage"}, {26, "stickerMessage"}, {28, "groupInviteMessage"},
		{29, "templateButtonReplyMessage"}, {30, "productMessage"}, {31, "deviceSentMessage"},
		{35, "messageContextInfo"}, {36, "listMessage"}, {37, "viewOnceMessage"},
		{38, "orderMessage"}, {39, "listResponseMessage"}, {40, "ephemeralMessage"},
		{41, "invoiceMessage"}, {42, "buttonsMessage"}, {43, "buttonsResponseMessage"},
		{44, "paymentInviteMessage"}, {45, "interactiveMessage"}, {46, "reactionMessage"},
		{47, "stickerSyncRmrMessage"}, {48, "interactiveResponseMessage"},
		{49, "pollCreationMessage"}, {50, "pollUpdateMessage"}, {51, "keepInChatMessage"},
		{53, "documentWithCaptionMessage"}, {54, "requestPhoneNumberMessage"},
		{55, "viewOnceMessageV2"}, {56, "encReactionMessage"}, {58, "editedMessage"},
		{59, "viewOnceMessageV2Extension"}, {60, "pollCreationMessageV2"},
		{61, "scheduledCallCreationMessage"}, {62, "groupMentionedMessage"},
		{63, "pinInChatMessage"}, {64, "pollCreationMessageV3"},
		{65, "scheduledCallEditMessage"}, {66, "ptvMessage"}, {67, "botInvokeMessage"},
		{69, "callLogMesssage"}, {70, "messageHistoryBundle"}, {71, "encCommentMessage"},
		{72, "bcallMessage"}, {74, "lottieStickerMessage"}, {75, "eventMessage"},
		{76, "encEventResponseMessage"}, {77, "commentMessage"},
		{78, "newsletterAdminInviteMessage"}, {80, "placeholderMessage"},
		{82, "secretEncryptedMessage"}, {83, "albumMessage"}, {85, "eventCoverImage"},
		{86, "stickerPackMessage"}, {87, "statusMentionMessage"},
		{88, "pollResultSnapshotMessage"}, {90, "pollCreationOptionImageMessage"},
		{91, "associatedChildMessage"}, {92, "groupStatusMentionMessage"},
		{93, "pollCreationMessageV4"}, {95, "statusAddYours"}, {96, "groupStatusMessage"},
		{97, "richResponseMessage"}, {98, "statusNotificationMessage"},
		{99, "limitSharingMessage"}, {100, "botTaskMessage"}, {101, "questionMessage"},
		{102, "messageHistoryNotice"}, {103, "groupStatusMessageV2"},
		{104, "botForwardedMessage"}, {105, "statusQuestionAnswerMessage"},
		{106, "questionReplyMessage"}, {107, "questionResponseMessage"},
		{109, "statusQuotedMessage"}, {110, "statusStickerInteractionMessage"},
		{111, "pollCreationMessageV5"}, {113, "newsletterFollowerInviteMessageV2"},
		{115, "pollResultSnapshotMessageV3"}, {116, "newsletterAdminProfileMessage"},
		{117, "newsletterAdminProfileMessageV2"}, {118, "spoilerMessage"},
		{119, "pollCreationMessageV6"}, {120, "conditionalRevealMessage"},
		{121, "pollAddOptionMessage"}, {122, "eventInviteMessage"},
		{123, "groupRootKeyShare"}, {124, "paymentReminderMessage"},
		{125, "splitPaymentMessage"}, {126, "newsletterAdminProfileStatusMessage"},
		{127, "rootSecretDistributeMessage"}, {128, "splitPaymentUpdateMessage"},
	}
	result := make(map[protoreflect.FieldNumber]MessageEntry, len(pinned))
	for _, field := range pinned {
		entry := decideMessageField(field.number, field.name)
		entry.Number, entry.Name = field.number, field.name
		entry.ProviderType = string(field.name)
		entry.Source = UpstreamCommit
		entry.Reference = "WuzAPI " + WuzAPICommit + " (documentary reference only)"
		if _, duplicate := result[field.number]; duplicate {
			panic(fmt.Sprintf("duplicate waE2E.Message field number %d", field.number))
		}
		result[field.number] = entry
	}
	return result
}

func decideMessageField(number protoreflect.FieldNumber, name protoreflect.Name) MessageEntry {
	entry := MessageEntry{
		Family: "UNSUPPORTED", Disposition: MessageUnsupported, Extractor: "extractPresenceOnly",
		Owner: "1.1/2.1", Evidence: "internal/protocol/message_normalizer.go; internal/protocol/message_normalizer_test.go",
	}
	project := func(family, extractor string, outbound bool) MessageEntry {
		return MessageEntry{
			Family: family, Disposition: MessageProjected, Extractor: extractor,
			OutboundSupported: outbound, Owner: "1.1/2.1",
			Evidence: "internal/protocol/message_normalizer.go; internal/protocol/message_normalizer_test.go",
		}
	}
	switch name {
	case "conversation":
		return project("TEXT", "extractConversation", true)
	case "extendedTextMessage":
		return project("TEXT", "extractExtendedText", true)
	case "imageMessage":
		return project("IMAGE", "extractImage", true)
	case "audioMessage":
		return project("AUDIO", "extractAudio", true)
	case "videoMessage", "ptvMessage":
		return project("VIDEO", "extractVideo", name == "videoMessage")
	case "documentMessage":
		return project("DOCUMENT", "extractDocument", true)
	case "stickerMessage":
		return project("STICKER", "extractSticker", true)
	case "locationMessage":
		return project("LOCATION", "extractLocation", true)
	case "liveLocationMessage":
		return project("LOCATION", "extractLiveLocation", false)
	case "contactMessage":
		return project("CONTACT", "extractContact", true)
	case "contactsArrayMessage":
		return project("CONTACT", "extractContacts", false)
	case "pollCreationMessage", "pollCreationMessageV2", "pollCreationMessageV3",
		"pollCreationMessageV5", "pollCreationMessageV6":
		return project("POLL", "extractPoll", true)
	case "templateMessage", "listMessage", "buttonsMessage", "interactiveMessage":
		return project("INTERACTIVE", "extractInteractive", true)
	case "templateButtonReplyMessage", "listResponseMessage", "buttonsResponseMessage",
		"interactiveResponseMessage":
		return project("INTERACTIVE", "extractInteractiveResponse", false)
	case "productMessage", "orderMessage", "sendPaymentMessage", "requestPaymentMessage",
		"declinePaymentRequestMessage", "cancelPaymentRequestMessage", "invoiceMessage",
		"paymentInviteMessage", "paymentReminderMessage", "splitPaymentMessage",
		"splitPaymentUpdateMessage", "groupInviteMessage", "eventMessage", "eventInviteMessage",
		"call", "scheduledCallCreationMessage", "scheduledCallEditMessage", "callLogMesssage":
		return project("INTERACTIVE", "extractRichCard", false)
	case "protocolMessage", "reactionMessage", "pollUpdateMessage":
		return MessageEntry{
			Family: "ACTION", Disposition: MessageAction, Extractor: "extractAction",
			Owner: "2.2", Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
		}
	case "secretEncryptedMessage", "ephemeralMessage", "documentWithCaptionMessage", "editedMessage",
		"deviceSentMessage", "associatedChildMessage", "pollCreationMessageV4":
		return MessageEntry{
			Family: "CONTROL", Disposition: MessageControl, Extractor: "unwrapMessage",
			Owner: "2.1/2.2", Evidence: "internal/protocol/message_normalizer.go; internal/protocol/message_normalizer_test.go",
		}
	case "albumMessage", "placeholderMessage":
		return MessageEntry{
			Family: "CONTROL", Disposition: MessageControl, Extractor: "consumeStructuralMarker",
			Owner: "2.1/2.2", Evidence: "internal/protocol/message_normalizer.go; internal/protocol/message_normalizer_test.go",
		}
	case "viewOnceMessage", "viewOnceMessageV2", "viewOnceMessageV2Extension":
		return MessageEntry{
			Family: "UNSUPPORTED", Disposition: MessageUnsupported, Extractor: "extractViewOnceUnavailable",
			Owner: "privacy-policy", Evidence: "internal/protocol/message_normalizer.go; internal/protocol/message_normalizer_test.go",
		}
	case "senderKeyDistributionMessage", "fastRatchetKeySenderKeyDistributionMessage",
		"bcallMessage", "groupMentionedMessage", "newsletterAdminInviteMessage",
		"statusMentionMessage", "groupStatusMentionMessage", "statusAddYours", "groupStatusMessage",
		"statusNotificationMessage", "groupStatusMessageV2", "statusQuestionAnswerMessage",
		"statusQuotedMessage", "statusStickerInteractionMessage", "newsletterFollowerInviteMessageV2",
		"newsletterAdminProfileMessage", "newsletterAdminProfileMessageV2",
		"newsletterAdminProfileStatusMessage", "groupRootKeyShare":
		return MessageEntry{
			Family: "OUT_OF_SCOPE", Disposition: MessageOutOfScope, Extractor: "rejectScope",
			Owner: "scope-policy", Evidence: "internal/protocol/message_normalizer_test.go; 1:1 product boundary",
		}
	default:
		_ = number
		return entry
	}
}
