package protocol

import (
	"bytes"
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

const (
	maxMessageTextBytes = 64 * 1024
	maxPreviewBytes     = 64 * 1024
	maxPollOptions      = 12
	maxInteractiveItems = 10
)

type streamingMessageClient interface {
	WhatsMeowClient
	UploadReader(context.Context, io.Reader, io.ReadWriteSeeker, whatsmeow.MediaType) (whatsmeow.UploadResponse, error)
	BuildPollCreation(string, []string, int) *waE2E.Message
}

// SendTypedMessage is the single transport path for rich 1:1 messages. It
// deliberately accepts product DTOs, not arbitrary protobufs.
func (a *WhatsMeowAdapter) SendTypedMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageSendPayload,
	providerMessageID string,
	content []byte,
) error {
	client, jid, err := a.readyRecipient(sessionID, payload.To)
	if err != nil {
		return err
	}
	if strings.TrimSpace(providerMessageID) == "" {
		return errors.New("provider message ID is required")
	}
	if err := validateTypedMessageCompatibility(payload); err != nil {
		return err
	}
	streaming, ok := client.(streamingMessageClient)
	if !ok {
		return errors.New("WhatsApp client does not support typed streaming messages")
	}

	var uploaded *whatsmeow.UploadResponse
	if payload.Media != nil {
		if len(content) == 0 {
			return errors.New("typed media content is empty")
		}
		temporary, err := os.CreateTemp("", "whatsapp-upload-*")
		if err != nil {
			return fmt.Errorf("create private upload buffer: %w", err)
		}
		path := temporary.Name()
		defer func() {
			_ = temporary.Close()
			_ = os.Remove(path)
		}()
		response, err := streaming.UploadReader(
			ctx, bytes.NewReader(content), temporary, mediaTypeForPayload(payload),
		)
		if err != nil {
			return err
		}
		uploaded = &response
	}

	message, err := buildTypedMessage(streaming, jid, payload, uploaded)
	if err != nil {
		return err
	}
	_, err = streaming.SendMessage(
		ctx, jid, message,
		whatsmeow.SendRequestExtra{ID: types.MessageID(providerMessageID)},
	)
	return err
}

func buildTypedMessage(
	client streamingMessageClient,
	to types.JID,
	payload domain.MessageSendPayload,
	uploaded *whatsmeow.UploadResponse,
) (*waE2E.Message, error) {
	kind := payload.Kind
	if !kind.Valid() {
		return nil, fmt.Errorf("unsupported message kind %q", kind)
	}
	if len(payload.Text) > maxMessageTextBytes || len(payload.Caption) > maxMessageTextBytes {
		return nil, errors.New("message text exceeds limit")
	}
	if err := validateTypedMessageCompatibility(payload); err != nil {
		return nil, err
	}
	contextInfo, err := messageContext(to, payload.ReplyTo)
	if err != nil {
		return nil, err
	}

	var message *waE2E.Message
	switch kind {
	case domain.MessageText:
		if strings.TrimSpace(payload.Text) == "" {
			return nil, errors.New("text message is empty")
		}
		if payload.LinkPreview == nil && contextInfo == nil {
			message = &waE2E.Message{Conversation: proto.String(payload.Text)}
		} else {
			extended := &waE2E.ExtendedTextMessage{Text: proto.String(payload.Text), ContextInfo: contextInfo}
			if payload.LinkPreview != nil {
				if err := applyLinkPreview(extended, payload.LinkPreview); err != nil {
					return nil, err
				}
			}
			message = &waE2E.Message{ExtendedTextMessage: extended}
		}
	case domain.MessageImage, domain.MessageAudio, domain.MessageVideo, domain.MessageDocument, domain.MessageSticker:
		if payload.Media == nil || uploaded == nil {
			return nil, errors.New("media descriptor and upload are required")
		}
		if err := validateMediaKind(kind, payload.Media.MIMEType); err != nil {
			return nil, err
		}
		if payload.Media.PTV {
			// The descriptor exposes PTV, but its wire builder has not passed the
			// pinned-version contract. Do not silently turn it into normal video.
			return nil, errors.New("PTV builder is unavailable")
		}
		message = typedMediaMessage(*uploaded, kind, payload)
		setMessageContext(message, contextInfo)
		if payload.Media.ViewOnce {
			if kind != domain.MessageImage && kind != domain.MessageVideo {
				return nil, errors.New("view-once flag is only valid for image or video messages")
			}
			message = &waE2E.Message{ViewOnceMessageV2: &waE2E.FutureProofMessage{Message: message}}
		}
	case domain.MessageLocation:
		if payload.Location == nil {
			return nil, errors.New("location payload is required")
		}
		if payload.Location.Latitude < -90 || payload.Location.Latitude > 90 ||
			payload.Location.Longitude < -180 || payload.Location.Longitude > 180 {
			return nil, errors.New("location coordinates are out of range")
		}
		message = &waE2E.Message{LocationMessage: &waE2E.LocationMessage{
			DegreesLatitude:  proto.Float64(payload.Location.Latitude),
			DegreesLongitude: proto.Float64(payload.Location.Longitude),
			Name:             proto.String(payload.Location.Name), Address: proto.String(payload.Location.Address),
			Comment: proto.String(payload.Caption), ContextInfo: contextInfo,
		}}
	case domain.MessageContact:
		if payload.Contact == nil && len(payload.Contacts) == 0 {
			return nil, errors.New("contact vcard is required")
		}
		contacts := payload.Contacts
		if payload.Contact != nil {
			contacts = []domain.ContactPayload{*payload.Contact}
		}
		for _, contact := range contacts {
			if strings.TrimSpace(contact.VCard) == "" {
				return nil, errors.New("contact vcard is required")
			}
		}
		if len(contacts) == 1 {
			message = &waE2E.Message{ContactMessage: &waE2E.ContactMessage{
				DisplayName: proto.String(contacts[0].DisplayName), Vcard: proto.String(contacts[0].VCard),
				ContextInfo: contextInfo,
			}}
		} else {
			items := make([]*waE2E.ContactMessage, 0, len(contacts))
			for _, contact := range contacts {
				items = append(items, &waE2E.ContactMessage{DisplayName: proto.String(contact.DisplayName), Vcard: proto.String(contact.VCard)})
			}
			message = &waE2E.Message{ContactsArrayMessage: &waE2E.ContactsArrayMessage{
				Contacts: items, ContextInfo: contextInfo,
			}}
		}
	case domain.MessageEvent:
		// EventMessage remains descriptor-only until its pinned whatsmeow builder
		// contract is proved. Keeping the DTO accepted lets Laravel capability-gate
		// it without accepting arbitrary protobuf fields.
		return nil, errors.New("event builder is unavailable")
	case domain.MessagePoll:
		if payload.Poll == nil || strings.TrimSpace(payload.Poll.Name) == "" ||
			len(payload.Poll.Options) < 2 || len(payload.Poll.Options) > maxPollOptions {
			return nil, errors.New("poll requires a name and 2 to 12 options")
		}
		if payload.Poll.SelectableOptions < 1 || payload.Poll.SelectableOptions > len(payload.Poll.Options) {
			return nil, errors.New("invalid selectable poll option count")
		}
		message = client.BuildPollCreation(
			payload.Poll.Name, payload.Poll.Options, payload.Poll.SelectableOptions,
		)
	case domain.MessageInteractive:
		message, err = buildInteractiveMessage(payload.Interactive, contextInfo)
		if err != nil {
			return nil, err
		}
	}
	return message, nil
}

func mediaTypeForPayload(payload domain.MessageSendPayload) whatsmeow.MediaType {
	if payload.Kind == domain.MessageSticker {
		return whatsmeow.MediaImage
	}
	if payload.Media == nil {
		return whatsmeow.MediaDocument
	}
	return whatsmeowMediaType(payload.Media.MIMEType)
}

func validateMediaKind(kind domain.MessageKind, mimeType string) error {
	mimeType = strings.ToLower(strings.TrimSpace(mimeType))
	valid := false
	switch kind {
	case domain.MessageImage:
		valid = strings.HasPrefix(mimeType, "image/")
	case domain.MessageAudio:
		valid = strings.HasPrefix(mimeType, "audio/")
	case domain.MessageVideo:
		valid = strings.HasPrefix(mimeType, "video/")
	case domain.MessageDocument:
		valid = mimeType != ""
	case domain.MessageSticker:
		valid = mimeType == "image/webp"
	}
	if !valid {
		return fmt.Errorf("MIME type %q does not match message kind %s", mimeType, kind)
	}
	return nil
}

func validateTypedMessageCompatibility(payload domain.MessageSendPayload) error {
	media := payload.Media
	if media == nil {
		return nil
	}
	if media.PTT && payload.Kind != domain.MessageAudio {
		return errors.New("PTT flag is only valid for audio messages")
	}
	if media.GIF && payload.Kind != domain.MessageVideo {
		return errors.New("GIF flag is only valid for video messages")
	}
	if media.PTV && payload.Kind != domain.MessageVideo {
		return errors.New("PTV flag is only valid for video messages")
	}
	if media.PTV {
		return errors.New("PTV builder is unavailable")
	}
	if media.ViewOnce && payload.Kind != domain.MessageImage && payload.Kind != domain.MessageVideo {
		return errors.New("view-once flag is only valid for image or video messages")
	}
	if (media.GIF && media.PTV) || (media.GIF && media.ViewOnce) || (media.PTV && media.ViewOnce) {
		return errors.New("incompatible media variants")
	}
	return nil
}

func typedMediaMessage(
	uploaded whatsmeow.UploadResponse,
	kind domain.MessageKind,
	payload domain.MessageSendPayload,
) *waE2E.Message {
	media := payload.Media
	caption := payload.Caption
	if caption == "" {
		caption = payload.Text
	}
	switch kind {
	case domain.MessageImage:
		return &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(media.MIMEType), Caption: proto.String(caption),
		}}
	case domain.MessageAudio:
		return &waE2E.Message{AudioMessage: &waE2E.AudioMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(media.MIMEType), PTT: proto.Bool(media.PTT),
		}}
	case domain.MessageVideo:
		return &waE2E.Message{VideoMessage: &waE2E.VideoMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(media.MIMEType), Caption: proto.String(caption), GifPlayback: proto.Bool(media.GIF),
		}}
	case domain.MessageSticker:
		return &waE2E.Message{StickerMessage: &waE2E.StickerMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(media.MIMEType),
		}}
	default:
		return &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(media.MIMEType), FileName: proto.String(media.Filename),
			Caption: proto.String(caption),
		}}
	}
}

func messageContext(to types.JID, reference *domain.MessageReference) (*waE2E.ContextInfo, error) {
	if reference == nil {
		return nil, nil
	}
	if strings.TrimSpace(reference.MessageID) == "" {
		return nil, errors.New("quoted message ID is required")
	}
	contextInfo := &waE2E.ContextInfo{
		StanzaID: proto.String(reference.MessageID), RemoteJID: proto.String(to.String()),
		QuotedMessage: &waE2E.Message{},
	}
	if reference.Sender != "" {
		parsed, err := parseTypedDirectJID(reference.Sender)
		if err != nil {
			return nil, fmt.Errorf("invalid quoted sender: %w", err)
		}
		contextInfo.Participant = proto.String(parsed.String())
	}
	return contextInfo, nil
}

func parseTypedDirectJID(value string) (types.JID, error) {
	address, err := NormalizeOneToOneAddress(value)
	if err != nil {
		return types.JID{}, err
	}
	return address.JID, nil
}

func applyLinkPreview(message *waE2E.ExtendedTextMessage, preview *domain.LinkPreviewPayload) error {
	if strings.TrimSpace(preview.URL) == "" {
		return errors.New("link preview URL is required")
	}
	message.MatchedText = proto.String(preview.URL)
	message.Title = proto.String(preview.Title)
	message.Description = proto.String(preview.Description)
	if preview.Thumbnail != "" {
		thumbnail, err := base64.StdEncoding.DecodeString(preview.Thumbnail)
		if err != nil {
			return errors.New("invalid link preview thumbnail")
		}
		if len(thumbnail) > maxPreviewBytes {
			return errors.New("link preview thumbnail exceeds limit")
		}
		message.JPEGThumbnail = thumbnail
	}
	return nil
}

func buildInteractiveMessage(
	interactive *domain.InteractivePayload,
	contextInfo *waE2E.ContextInfo,
) (*waE2E.Message, error) {
	if interactive == nil {
		return nil, errors.New("interactive payload is required")
	}
	mode := strings.ToUpper(strings.TrimSpace(interactive.Mode))
	if mode != "LIST" {
		return nil, errors.New("interactive mode must be LIST")
	}
	if len(interactive.Options) == 0 || len(interactive.Options) > maxInteractiveItems {
		return nil, errors.New("interactive message requires 1 to 10 options")
	}
	rows := make([]*waE2E.ListMessage_Row, 0, len(interactive.Options))
	for index, option := range interactive.Options {
		option = strings.TrimSpace(option)
		if option == "" {
			return nil, errors.New("interactive option is empty")
		}
		rows = append(rows, &waE2E.ListMessage_Row{
			Title: proto.String(option), RowID: proto.String(fmt.Sprintf("option-%d", index+1)),
		})
	}
	return &waE2E.Message{ListMessage: &waE2E.ListMessage{
		Title: proto.String(interactive.Title), Description: proto.String(interactive.Description),
		ButtonText: proto.String("Opções"), ListType: waE2E.ListMessage_SINGLE_SELECT.Enum(),
		Sections: []*waE2E.ListMessage_Section{{Rows: rows}}, ContextInfo: contextInfo,
	}}, nil
}

func setMessageContext(message *waE2E.Message, contextInfo *waE2E.ContextInfo) {
	if contextInfo == nil || message == nil {
		return
	}
	switch {
	case message.ImageMessage != nil:
		message.ImageMessage.ContextInfo = contextInfo
	case message.AudioMessage != nil:
		message.AudioMessage.ContextInfo = contextInfo
	case message.VideoMessage != nil:
		message.VideoMessage.ContextInfo = contextInfo
	case message.DocumentMessage != nil:
		message.DocumentMessage.ContextInfo = contextInfo
	case message.StickerMessage != nil:
		message.StickerMessage.ContextInfo = contextInfo
	}
}
