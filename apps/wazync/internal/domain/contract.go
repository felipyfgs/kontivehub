package domain

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"sort"
	"strings"
	"time"
)

const (
	maxOutboundContacts       = 10
	maxContactDisplayNameByte = 512
	maxContactVCardBytes      = 64 * 1024
	maxEventTitleBytes        = 512
	maxEventDescriptionBytes  = 2048
	maxEventTimezoneBytes     = 64
	maxEventLocationNameBytes = 512
	maxEventLocationAddrBytes = 2048
)

type MessageKind string

const (
	MessageText        MessageKind = "TEXT"
	MessageImage       MessageKind = "IMAGE"
	MessageAudio       MessageKind = "AUDIO"
	MessageVideo       MessageKind = "VIDEO"
	MessageDocument    MessageKind = "DOCUMENT"
	MessageSticker     MessageKind = "STICKER"
	MessageLocation    MessageKind = "LOCATION"
	MessageContact     MessageKind = "CONTACT"
	MessageEvent       MessageKind = "EVENT"
	MessagePoll        MessageKind = "POLL"
	MessageInteractive MessageKind = "INTERACTIVE"
	MessageUnsupported MessageKind = "UNSUPPORTED"
)

func (k MessageKind) Valid() bool {
	switch k {
	case MessageText, MessageImage, MessageAudio, MessageVideo, MessageDocument,
		MessageSticker, MessageLocation, MessageContact, MessageEvent, MessagePoll, MessageInteractive,
		MessageUnsupported:
		return true
	default:
		return false
	}
}

type EmptyPayload struct{}

type SessionProvisionPayload struct {
	DesiredConnected bool `json:"desired_connected"`
}

type PairPhonePayload struct {
	Phone                string `json:"phone"`
	ShowPushNotification bool   `json:"show_push_notification,omitempty"`
}

type PasskeyResponsePayload struct {
	ID             string `json:"id"`
	ClientDataJSON string `json:"client_data_json"`
	Authenticator  string `json:"authenticator_data"`
	Signature      string `json:"signature"`
}

type PasskeyConfirmationPayload struct {
	ID      string `json:"id"`
	Confirm bool   `json:"confirm"`
}

type PassivePayload struct {
	Passive bool `json:"passive"`
}

type MessageReference struct {
	MessageID string `json:"message_id"`
	Sender    string `json:"sender,omitempty"`
}

type MediaReference struct {
	AttachmentID int64  `json:"attachment_id,omitempty"`
	Filename     string `json:"filename"`
	MIMEType     string `json:"mime_type"`
	SizeBytes    int64  `json:"size_bytes"`
	SHA256       string `json:"sha256"`
	PTT          bool   `json:"ptt,omitempty"`
	GIF          bool   `json:"gif,omitempty"`
	PTV          bool   `json:"ptv,omitempty"`
	ViewOnce     bool   `json:"view_once,omitempty"`
}

type LinkPreviewPayload struct {
	URL         string `json:"url"`
	Title       string `json:"title,omitempty"`
	Description string `json:"description,omitempty"`
	Thumbnail   string `json:"thumbnail_base64,omitempty"`
}

type LocationPayload struct {
	Latitude  float64 `json:"latitude"`
	Longitude float64 `json:"longitude"`
	Name      string  `json:"name,omitempty"`
	Address   string  `json:"address,omitempty"`
}

type ContactPayload struct {
	DisplayName string `json:"display_name"`
	VCard       string `json:"vcard"`
}

// EventPayload is deliberately a product DTO. The pinned protocol descriptor
// is checked separately before this family can be advertised or sent.
type EventPayload struct {
	Title                string `json:"title"`
	Description          string `json:"description,omitempty"`
	StartAt              string `json:"start_at"`
	EndAt                string `json:"end_at,omitempty"`
	Timezone             string `json:"timezone"`
	LocationName         string `json:"location_name,omitempty"`
	LocationAddress      string `json:"location_address,omitempty"`
	ParticipationEnabled bool   `json:"participation_enabled,omitempty"`
}

type MessageBatchItemPayload struct {
	BatchID           string             `json:"batch_id"`
	Position          int                `json:"position"`
	Size              int                `json:"size"`
	ProviderMessageID string             `json:"provider_message_id"`
	Message           MessageSendPayload `json:"message"`
}

// MessageBatchPayload is a complete, bounded command envelope. Items carry
// their stable correlation explicitly so JSON order can never define delivery
// order accidentally.
type MessageBatchPayload struct {
	BatchID     string                    `json:"batch_id"`
	Size        int                       `json:"size"`
	AlbumNative bool                      `json:"album_native"`
	Items       []MessageBatchItemPayload `json:"items"`
}

type PollPayload struct {
	Name              string   `json:"name"`
	Options           []string `json:"options"`
	SelectableOptions int      `json:"selectable_options"`
}

type InteractivePayload struct {
	Mode        string   `json:"mode"`
	Title       string   `json:"title,omitempty"`
	Description string   `json:"description,omitempty"`
	Options     []string `json:"options"`
}

type MessageSendPayload struct {
	To          string              `json:"to"`
	Kind        MessageKind         `json:"kind"`
	Text        string              `json:"text,omitempty"`
	Caption     string              `json:"caption,omitempty"`
	ReplyTo     *MessageReference   `json:"reply_to,omitempty"`
	LinkPreview *LinkPreviewPayload `json:"link_preview,omitempty"`
	Media       *MediaReference     `json:"media,omitempty"`
	Location    *LocationPayload    `json:"location,omitempty"`
	Contact     *ContactPayload     `json:"contact,omitempty"`
	Contacts    []ContactPayload    `json:"contacts,omitempty"`
	Event       *EventPayload       `json:"event,omitempty"`
	Poll        *PollPayload        `json:"poll,omitempty"`
	Interactive *InteractivePayload `json:"interactive,omitempty"`
}

type MessageTargetPayload struct {
	To              string `json:"to"`
	TargetMessageID string `json:"target_message_id"`
	Sender          string `json:"sender,omitempty"`
}

type MessageEditPayload struct {
	MessageTargetPayload
	Text string `json:"text"`
}

type MessageReactionPayload struct {
	MessageTargetPayload
	Emoji string `json:"emoji"`
}

type PollVotePayload struct {
	MessageTargetPayload
	OptionNames []string `json:"option_names"`
}

type MessageMarkPayload struct {
	To         string   `json:"to"`
	MessageIDs []string `json:"message_ids"`
	Receipt    string   `json:"receipt"`
	Sender     string   `json:"sender,omitempty"`
	Timestamp  int64    `json:"timestamp,omitempty"`
	Protocol   bool     `json:"protocol,omitempty"`
}

type PresencePayload struct {
	Presence                    string `json:"presence"`
	ForceActiveDeliveryReceipts *bool  `json:"force_active_delivery_receipts,omitempty"`
}

type ContactPresencePayload struct {
	To string `json:"to"`
}

type ChatPresencePayload struct {
	To       string `json:"to"`
	Presence string `json:"presence"`
	Media    string `json:"media,omitempty"`
}

type DisappearingPayload struct {
	To           string `json:"to"`
	TimerSeconds uint32 `json:"timer_seconds"`
}

type ChatStatePayload struct {
	To              string `json:"to"`
	Action          string `json:"action"`
	Value           bool   `json:"value,omitempty"`
	TargetMessageID string `json:"target_message_id,omitempty"`
	Sender          string `json:"sender,omitempty"`
	Timestamp       int64  `json:"timestamp,omitempty"`
	DurationSeconds uint32 `json:"duration_seconds,omitempty"`
	DeleteMedia     bool   `json:"delete_media,omitempty"`
	FromMe          bool   `json:"from_me,omitempty"`
}

type BlocklistUpdatePayload struct {
	To     string `json:"to"`
	Action string `json:"action"`
}

type PrivacyUpdatePayload struct {
	Name  string `json:"name"`
	Value string `json:"value"`
}

type DefaultDisappearingPayload struct {
	TimerSeconds uint32 `json:"timer_seconds"`
}

type HistorySyncPayload struct {
	To                   string `json:"to"`
	LastMessageID        string `json:"last_message_id"`
	LastMessageFrom      string `json:"last_message_from"`
	LastMessageTimestamp int64  `json:"last_message_timestamp"`
	LastMessageFromMe    bool   `json:"last_message_from_me"`
	Count                int    `json:"count"`
}

type MediaRetryPayload struct {
	To                string `json:"to"`
	TargetMessageID   string `json:"target_message_id"`
	ExpectedDirection string `json:"expected_direction,omitempty"`
	// Sender and FromMe are accepted only for inbound payloads without
	// expected_direction. Callers using expected_direction never learn the
	// session identity kept in the encrypted descriptor.
	Sender string `json:"sender,omitempty"`
	FromMe bool   `json:"from_me,omitempty"`
}

const MaxStickerMaterializationBytes int64 = 1 << 20

type StickerMaterializationPayload struct {
	ObservationID    string `json:"observation_id"`
	ExpectedSHA256   string `json:"expected_sha256"`
	ExpectedMIMEType string `json:"expected_mime_type"`
	MaxBytes         int64  `json:"max_bytes"`
}

func (p MediaRetryPayload) MarshalJSON() ([]byte, error) {
	type wirePayload struct {
		To                string `json:"to"`
		TargetMessageID   string `json:"target_message_id"`
		ExpectedDirection string `json:"expected_direction,omitempty"`
		Sender            string `json:"sender,omitempty"`
		FromMe            *bool  `json:"from_me,omitempty"`
	}
	var fromMe *bool
	if strings.TrimSpace(p.Sender) != "" {
		value := p.FromMe
		fromMe = &value
	}
	return json.Marshal(wirePayload{
		To:                p.To,
		TargetMessageID:   p.TargetMessageID,
		ExpectedDirection: p.ExpectedDirection,
		Sender:            p.Sender,
		FromMe:            fromMe,
	})
}

type UsersQueryPayload struct {
	Users []string `json:"users"`
}

type UserQueryPayload struct {
	User string `json:"user"`
}

type ProfilePictureQueryPayload struct {
	User    string `json:"user"`
	Preview bool   `json:"preview,omitempty"`
}

type ContactQRQueryPayload struct {
	Revoke bool `json:"revoke,omitempty"`
}

type LinkQueryPayload struct {
	Link string `json:"link"`
}

func (c Command) ValidatePayload() error {
	switch c.Type {
	case CommandProvisionSession:
		return decodePayload(c.Payload, &SessionProvisionPayload{})
	case CommandPairSession, CommandConnectSession, CommandDisconnectSession,
		CommandLogoutSession:
		return decodePayload(c.Payload, &EmptyPayload{})
	case CommandPairPhone:
		return decodePayload(c.Payload, &PairPhonePayload{})
	case CommandPasskeyRespond:
		return decodePayload(c.Payload, &PasskeyResponsePayload{})
	case CommandPasskeyConfirm:
		return decodePayload(c.Payload, &PasskeyConfirmationPayload{})
	case CommandSetPassive:
		return decodePayload(c.Payload, &PassivePayload{})
	case CommandSendMessage:
		var payload MessageSendPayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		return payload.Validate()
	case CommandSendMessageBatch:
		var payload MessageBatchPayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		return payload.Validate()
	case CommandEditMessage:
		return decodePayload(c.Payload, &MessageEditPayload{})
	case CommandRevokeMessage, CommandRequestUnavailableMessage:
		return decodePayload(c.Payload, &MessageTargetPayload{})
	case CommandReactMessage:
		return decodePayload(c.Payload, &MessageReactionPayload{})
	case CommandVotePoll:
		return decodePayload(c.Payload, &PollVotePayload{})
	case CommandMarkMessage:
		return decodePayload(c.Payload, &MessageMarkPayload{})
	case CommandRetryMedia:
		var payload MediaRetryPayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		return validateMediaRetryPayload(c.Payload, payload)
	case CommandMaterializeSticker:
		var payload StickerMaterializationPayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		if !identifierPattern.MatchString(payload.ObservationID) ||
			!validSHA256Hex(payload.ExpectedSHA256) || payload.ExpectedMIMEType != "image/webp" ||
			payload.MaxBytes < 1 || payload.MaxBytes > MaxStickerMaterializationBytes {
			return errors.New("invalid sticker materialization payload")
		}
		return nil
	case CommandSetPresence:
		return decodePayload(c.Payload, &PresencePayload{})
	case CommandSubscribePresence:
		return decodePayload(c.Payload, &ContactPresencePayload{})
	case CommandSetChatPresence:
		return decodePayload(c.Payload, &ChatPresencePayload{})
	case CommandSetDisappearing:
		return decodePayload(c.Payload, &DisappearingPayload{})
	case CommandUpdateChatState:
		var payload ChatStatePayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		switch strings.ToUpper(strings.TrimSpace(payload.Action)) {
		case "SYNC":
			return nil
		case "MARK_CLEAN":
			if payload.Timestamp <= 0 {
				return errors.New("mark-clean timestamp is required")
			}
			return nil
		case "ARCHIVE", "MUTE", "PIN", "STAR", "MARK_READ", "DELETE_CHAT":
			if strings.TrimSpace(payload.To) == "" {
				return errors.New("chat-state recipient is required")
			}
			return nil
		default:
			return errors.New("unsupported chat-state action")
		}
	case CommandUpdateBlocklist:
		return decodePayload(c.Payload, &BlocklistUpdatePayload{})
	case CommandUpdatePrivacy:
		return decodePayload(c.Payload, &PrivacyUpdatePayload{})
	case CommandSetDefaultDisappearing:
		return decodePayload(c.Payload, &DefaultDisappearingPayload{})
	case CommandRequestHistorySync:
		var payload HistorySyncPayload
		if err := decodePayload(c.Payload, &payload); err != nil {
			return err
		}
		if strings.TrimSpace(payload.To) == "" || strings.TrimSpace(payload.LastMessageID) == "" ||
			strings.TrimSpace(payload.LastMessageFrom) == "" || payload.LastMessageTimestamp <= 0 ||
			payload.Count < 1 || payload.Count > 50 {
			return errors.New("invalid history sync cursor or count")
		}
		return nil
	default:
		return errors.New("unsupported command payload")
	}
}

func validateMediaRetryPayload(raw json.RawMessage, payload MediaRetryPayload) error {
	if strings.TrimSpace(payload.To) == "" || strings.TrimSpace(payload.TargetMessageID) == "" {
		return errors.New("media retry recipient and target are required")
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(raw, &fields); err != nil {
		return errors.New("invalid media retry payload")
	}
	_, hasSender := fields["sender"]
	_, hasFromMe := fields["from_me"]
	_, hasExpectedDirection := fields["expected_direction"]
	expected := strings.ToUpper(strings.TrimSpace(payload.ExpectedDirection))
	if hasExpectedDirection {
		if expected != "INBOUND" && expected != "OUTBOUND" || hasSender || hasFromMe {
			return errors.New("invalid media retry v2 payload")
		}
		return nil
	}
	// The compatibility shape is deliberately inbound-only. Requiring both
	// fields prevents a missing bool from silently becoming false.
	if !hasSender || !hasFromMe || strings.TrimSpace(payload.Sender) == "" || payload.FromMe {
		return errors.New("invalid inbound media retry payload")
	}
	var fromMe *bool
	if err := json.Unmarshal(fields["from_me"], &fromMe); err != nil || fromMe == nil || *fromMe {
		return errors.New("invalid inbound media retry payload")
	}
	return nil
}

func (p MessageSendPayload) Validate() error {
	if strings.TrimSpace(p.To) == "" || !p.Kind.Valid() || p.Kind == MessageUnsupported {
		return errors.New("message recipient and supported kind are required")
	}

	switch p.Kind {
	case MessageText:
		if strings.TrimSpace(p.Text) == "" || p.Media != nil || p.Location != nil ||
			p.Contact != nil || len(p.Contacts) != 0 || p.Event != nil || p.Poll != nil || p.Interactive != nil {
			return errors.New("invalid text message payload")
		}
	case MessageImage, MessageAudio, MessageVideo, MessageDocument, MessageSticker:
		if p.Media == nil || strings.TrimSpace(p.Media.Filename) == "" ||
			strings.TrimSpace(p.Media.MIMEType) == "" || p.Location != nil ||
			p.Contact != nil || len(p.Contacts) != 0 || p.Event != nil || p.Poll != nil || p.Interactive != nil {
			return errors.New("invalid media message payload")
		}
		if p.Media.PTT && p.Kind != MessageAudio {
			return errors.New("PTT flag is only valid for audio messages")
		}
		if p.Media.GIF && p.Kind != MessageVideo {
			return errors.New("GIF flag is only valid for video messages")
		}
		if p.Media.PTV && p.Kind != MessageVideo {
			return errors.New("PTV flag is only valid for video messages")
		}
		if p.Media.ViewOnce && p.Kind != MessageImage && p.Kind != MessageVideo {
			return errors.New("view-once flag is only valid for image or video messages")
		}
		if (p.Media.GIF && p.Media.PTV) || (p.Media.GIF && p.Media.ViewOnce) || (p.Media.PTV && p.Media.ViewOnce) {
			return errors.New("incompatible media variants")
		}
	case MessageLocation:
		if p.Location == nil || p.Media != nil || p.Contact != nil || len(p.Contacts) != 0 || p.Event != nil ||
			p.Poll != nil || p.Interactive != nil {
			return errors.New("invalid location message payload")
		}
	case MessageContact:
		if (p.Contact == nil && len(p.Contacts) == 0) || (p.Contact != nil && len(p.Contacts) != 0) || len(p.Contacts) > maxOutboundContacts || p.Media != nil || p.Location != nil || p.Event != nil ||
			p.Poll != nil || p.Interactive != nil {
			return errors.New("invalid contact message payload")
		}
		if p.Contact != nil {
			return validateOutboundContact(*p.Contact)
		}
		for _, contact := range p.Contacts {
			if err := validateOutboundContact(contact); err != nil {
				return err
			}
		}
	case MessageEvent:
		if p.Event == nil || p.Media != nil || p.Location != nil || p.Contact != nil || len(p.Contacts) != 0 || p.Poll != nil || p.Interactive != nil {
			return errors.New("invalid event message payload")
		}
		return validateOutboundEvent(*p.Event)
	case MessagePoll:
		if p.Poll == nil || p.Media != nil || p.Location != nil || p.Event != nil ||
			p.Contact != nil || len(p.Contacts) != 0 || p.Interactive != nil {
			return errors.New("invalid poll message payload")
		}
	case MessageInteractive:
		if p.Interactive == nil || p.Media != nil || p.Location != nil || p.Event != nil ||
			p.Contact != nil || len(p.Contacts) != 0 || p.Poll != nil {
			return errors.New("invalid interactive message payload")
		}
	}

	return nil
}

func (p MessageBatchPayload) Validate() error {
	if !identifierPattern.MatchString(p.BatchID) || p.Size < 2 || p.Size > 10 || len(p.Items) != p.Size {
		return errors.New("batch ID and 2 to 10 ordered media items are required")
	}
	if p.AlbumNative {
		return errors.New("native albums are unavailable")
	}
	positions := make(map[int]struct{}, p.Size)
	recipient := ""
	for _, item := range p.Items {
		if item.BatchID != p.BatchID || item.Size != p.Size || item.Position < 0 || item.Position >= p.Size ||
			!identifierPattern.MatchString(item.ProviderMessageID) {
			return errors.New("invalid batch item correlation")
		}
		if _, duplicate := positions[item.Position]; duplicate {
			return errors.New("duplicate batch item position")
		}
		positions[item.Position] = struct{}{}
		if err := item.Message.Validate(); err != nil {
			return err
		}
		if item.Message.Kind != MessageImage && item.Message.Kind != MessageVideo && item.Message.Kind != MessageDocument {
			return errors.New("batch items must be image, video, or document media")
		}
		if recipient == "" {
			recipient = item.Message.To
		} else if item.Message.To != recipient {
			return errors.New("batch items must have one recipient")
		}
	}
	for position := range p.Size {
		if _, ok := positions[position]; !ok {
			return errors.New("batch item positions are incomplete")
		}
	}
	return nil
}

func (p MessageBatchPayload) OrderedItems() []MessageBatchItemPayload {
	items := append([]MessageBatchItemPayload(nil), p.Items...)
	sort.Slice(items, func(left, right int) bool {
		return items[left].Position < items[right].Position
	})
	return items
}

func validateOutboundContact(contact ContactPayload) error {
	if strings.TrimSpace(contact.DisplayName) == "" || strings.TrimSpace(contact.VCard) == "" ||
		len(contact.DisplayName) > maxContactDisplayNameByte || len(contact.VCard) > maxContactVCardBytes {
		return errors.New("invalid bounded contact payload")
	}
	return nil
}

func validateOutboundEvent(event EventPayload) error {
	if strings.TrimSpace(event.Title) == "" || strings.TrimSpace(event.StartAt) == "" || strings.TrimSpace(event.Timezone) == "" ||
		len(event.Title) > maxEventTitleBytes || len(event.Description) > maxEventDescriptionBytes ||
		len(event.Timezone) > maxEventTimezoneBytes || len(event.LocationName) > maxEventLocationNameBytes ||
		len(event.LocationAddress) > maxEventLocationAddrBytes {
		return errors.New("invalid bounded event payload")
	}
	start, err := time.Parse(time.RFC3339, event.StartAt)
	if err != nil {
		return errors.New("event start_at must be RFC3339")
	}
	if strings.TrimSpace(event.EndAt) == "" {
		return nil
	}
	end, err := time.Parse(time.RFC3339, event.EndAt)
	if err != nil || !end.After(start) {
		return errors.New("event end_at must be after start_at")
	}
	return nil
}

func (q Query) ValidatePayload() error {
	switch q.Type {
	case QueryIsOnWhatsApp:
		return decodePayload(q.Payload, &UsersQueryPayload{})
	case QueryUserInfo, QueryContactProfiles, QueryBusinessProfile:
		return decodePayload(q.Payload, &UsersQueryPayload{})
	case QueryProfilePicture:
		return decodePayload(q.Payload, &ProfilePictureQueryPayload{})
	case QueryContactQRLink:
		return decodePayload(q.Payload, &ContactQRQueryPayload{})
	case QueryResolveContactQR, QueryResolveBusinessLink:
		return decodePayload(q.Payload, &LinkQueryPayload{})
	case QueryBlocklist, QueryPrivacySettings:
		return decodePayload(q.Payload, &EmptyPayload{})
	default:
		return errors.New("unsupported query payload")
	}
}

func decodePayload(payload json.RawMessage, destination any) error {
	decoder := json.NewDecoder(bytes.NewReader(payload))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return err
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return errors.New("unexpected content after payload")
	}
	return nil
}

func validSHA256Hex(value string) bool {
	if len(value) != 64 || value != strings.ToLower(value) {
		return false
	}
	for _, character := range value {
		if (character < '0' || character > '9') && (character < 'a' || character > 'f') {
			return false
		}
	}
	return true
}
