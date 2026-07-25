package protocol

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waWeb"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

const (
	MaxHistoryRequestMessages = 50
	maxHistoryDownloadBytes   = 64 << 20
	maxHistoryBatchMessages   = 500
	maxThumbnailBytes         = 2 << 20
	maxStickerPackItems       = 100
	maxStickerBytes           = 16 << 20
	maxMediaRetryEntries      = 512
	mediaRetryTTL             = 15 * time.Minute
)

type mediaRetryEnvelope struct {
	Message  []byte `json:"m"`
	Chat     string `json:"c"`
	Sender   string `json:"s"`
	IsFromMe bool   `json:"f"`
}

var (
	ErrHistoryRecoveryInvalid = errors.New("invalid history recovery request")
	ErrMediaRetryStateMissing = errors.New("media retry state is unavailable")
	ErrRecoveryLimitExceeded  = errors.New("recovery limit exceeded")
	stickerPackIDPattern      = regexp.MustCompile(`^[A-Za-z0-9_-]{1,128}$`)
)

type historyRequestClient interface {
	BuildHistorySyncRequest(*types.MessageInfo, int) *waE2E.Message
	SendPeerMessage(context.Context, *waE2E.Message) (whatsmeow.SendResponse, error)
}

type historyDownloadClient interface {
	DownloadHistorySync(context.Context, *waE2E.HistorySyncNotification, bool) (*waHistorySync.HistorySync, error)
	DeleteMedia(context.Context, whatsmeow.MediaType, string, []byte, string) error
	ParseWebMessage(types.JID, *waWeb.WebMessageInfo) (*events.Message, error)
	SendHistorySyncServerErrorReceipt(context.Context, types.MessageID, []byte) error
}

type mediaRetryClient interface {
	SendMediaRetryReceipt(context.Context, *types.MessageInfo, []byte) error
}

type thumbnailClient interface {
	DownloadThumbnail(context.Context, whatsmeow.DownloadableThumbnail) ([]byte, error)
}

type stickerPackClient interface {
	FetchStickerPack(context.Context, string) (*types.StickerPack, error)
}

var (
	_ historyRequestClient  = (*whatsmeow.Client)(nil)
	_ historyDownloadClient = (*whatsmeow.Client)(nil)
	_ mediaRetryClient      = (*whatsmeow.Client)(nil)
	_ thumbnailClient       = (*whatsmeow.Client)(nil)
	_ stickerPackClient     = (*whatsmeow.Client)(nil)
)

type mediaRetrySecret struct {
	info      types.MessageInfo
	mediaKey  []byte
	expiresAt time.Time
}

// HistoryRecoveryBatch is internal-only. The event bridge must normalize each
// parsed message before appending it to the durable ledger; upstream protobufs
// in Messages must never be serialized into the gateway contract.
type HistoryRecoveryBatch struct {
	SyncType          waHistorySync.HistorySync_HistorySyncType
	ChunkOrder        uint32
	Progress          uint32
	Messages          []*events.Message
	RejectedScope     int
	DuplicateMessages int
	InvalidMessages   int
	LimitedMessages   int
}

type StickerPackSummary struct {
	ID          string
	Name        string
	Publisher   string
	Description string
	Animated    bool
	Lottie      bool
	Stickers    []StickerSummary
}

type StickerSummary struct {
	MIMEType          string
	SizeBytes         int64
	Width             int
	Height            int
	Emojis            []string
	AccessibilityText string
}

// RequestHistorySync asks the primary device for a bounded 1:1 history batch.
// The cursor timestamp is required because whatsmeow places it in the peer
// request even though the protobuf field name misleadingly ends in "MS".
func (a *WhatsMeowAdapter) RequestHistorySync(
	ctx context.Context,
	sessionID string,
	payload domain.HistorySyncPayload,
) error {
	client, chat, err := a.readyRecoveryRecipient(sessionID, payload.To)
	if err != nil {
		return err
	}
	historyClient, ok := client.(historyRequestClient)
	if !ok {
		return errors.New("WhatsApp client does not support history requests")
	}
	if strings.TrimSpace(payload.LastMessageID) == "" || payload.LastMessageTimestamp <= 0 ||
		payload.Count < 1 || payload.Count > MaxHistoryRequestMessages {
		return ErrHistoryRecoveryInvalid
	}
	if payload.LastMessageFrom != "" {
		cursorSender, err := NormalizeOneToOneAddress(payload.LastMessageFrom)
		if err != nil {
			return err
		}
		if !payload.LastMessageFromMe && cursorSender.JID != chat {
			return ErrHistoryRecoveryInvalid
		}
	}
	request := historyClient.BuildHistorySyncRequest(&types.MessageInfo{
		MessageSource: types.MessageSource{
			Chat:     chat,
			IsFromMe: payload.LastMessageFromMe,
		},
		ID:        types.MessageID(payload.LastMessageID),
		Timestamp: time.Unix(payload.LastMessageTimestamp, 0),
	}, payload.Count)
	if request == nil {
		return ErrHistoryRecoveryInvalid
	}
	_, err = historyClient.SendPeerMessage(ctx, request)
	return err
}

// RecoverHistorySync downloads, parses, filters and finally deletes a history
// blob using only metadata from the upstream notification. Direct paths and
// media keys never enter a public command or event payload.
func (a *WhatsMeowAdapter) RecoverHistorySync(
	ctx context.Context,
	sessionID string,
	notification *waE2E.HistorySyncNotification,
	requestedAddress string,
) (HistoryRecoveryBatch, error) {
	var target *types.JID
	if requestedAddress != "" {
		address, err := NormalizeOneToOneAddress(requestedAddress)
		if err != nil {
			return HistoryRecoveryBatch{}, err
		}
		target = &address.JID
	}
	if notification == nil ||
		(notification.GetFileLength() > maxHistoryDownloadBytes && len(notification.GetInitialHistBootstrapInlinePayload()) == 0) {
		return HistoryRecoveryBatch{}, ErrHistoryRecoveryInvalid
	}
	client, err := a.readyRecoveryClient(sessionID)
	if err != nil {
		return HistoryRecoveryBatch{}, err
	}
	historyClient, ok := client.(historyDownloadClient)
	if !ok {
		return HistoryRecoveryBatch{}, errors.New("WhatsApp client does not support history recovery")
	}
	history, err := historyClient.DownloadHistorySync(ctx, notification, true)
	if err != nil {
		messageID := strings.TrimSpace(notification.GetOriginalMessageID())
		mediaKey := notification.GetMediaKey()
		if messageID != "" && len(mediaKey) == 32 {
			if receiptErr := historyClient.SendHistorySyncServerErrorReceipt(
				ctx, types.MessageID(messageID), mediaKey,
			); receiptErr != nil {
				return HistoryRecoveryBatch{}, errors.Join(err, receiptErr)
			}
		}
		return HistoryRecoveryBatch{}, err
	}
	if history == nil {
		return HistoryRecoveryBatch{}, ErrHistoryRecoveryInvalid
	}
	batch := parseHistorySync(historyClient, history, target)
	if directPath := notification.GetDirectPath(); directPath != "" {
		if len(directPath) > 4096 || len(notification.GetFileEncSHA256()) != 32 {
			return batch, ErrHistoryRecoveryInvalid
		}
		if err := historyClient.DeleteMedia(
			ctx,
			whatsmeow.MediaHistory,
			directPath,
			notification.GetFileEncSHA256(),
			notification.GetEncHandle(),
		); err != nil {
			return batch, fmt.Errorf("delete processed history media: %w", err)
		}
	}
	return batch, nil
}

func parseHistorySync(
	client historyDownloadClient,
	history *waHistorySync.HistorySync,
	target *types.JID,
) HistoryRecoveryBatch {
	batch := HistoryRecoveryBatch{
		SyncType: history.GetSyncType(), ChunkOrder: history.GetChunkOrder(), Progress: history.GetProgress(),
		Messages: make([]*events.Message, 0),
	}
	seen := make(map[string]struct{})
	for _, conversation := range history.GetConversations() {
		if conversation == nil {
			continue
		}
		chat, err := types.ParseJID(conversation.GetID())
		if err != nil {
			batch.InvalidMessages += len(conversation.GetMessages())
			continue
		}
		normalized, err := NormalizeOneToOneJID(chat)
		if err != nil {
			batch.RejectedScope += len(conversation.GetMessages())
			continue
		}
		if target != nil && normalized.JID != target.ToNonAD() {
			batch.RejectedScope += len(conversation.GetMessages())
			continue
		}
		for _, historyMessage := range conversation.GetMessages() {
			if len(batch.Messages) >= maxHistoryBatchMessages {
				batch.LimitedMessages++
				continue
			}
			if historyMessage == nil || historyMessage.GetMessage() == nil {
				batch.InvalidMessages++
				continue
			}
			messageID := strings.TrimSpace(historyMessage.GetMessage().GetKey().GetID())
			if messageID == "" {
				batch.InvalidMessages++
				continue
			}
			dedupeKey := normalized.JID.String() + "\x00" + messageID
			if _, exists := seen[dedupeKey]; exists {
				batch.DuplicateMessages++
				continue
			}
			parsed, err := client.ParseWebMessage(normalized.JID, historyMessage.GetMessage())
			if err != nil || parsed == nil || parsed.Info.ID == "" {
				batch.InvalidMessages++
				continue
			}
			if _, err := normalizeMessageSource(parsed.Info.MessageSource); err != nil {
				batch.RejectedScope++
				continue
			}
			seen[dedupeKey] = struct{}{}
			batch.Messages = append(batch.Messages, parsed)
		}
	}
	return batch
}

// RememberMediaRetry keeps the key only inside the gateway process. Entries
// are bounded and expire; the command contract carries only stable IDs and
// 1:1 addressing information.
func (a *WhatsMeowAdapter) RememberMediaRetry(
	sessionID string,
	info types.MessageInfo,
	mediaKey []byte,
) error {
	if strings.TrimSpace(sessionID) == "" || strings.TrimSpace(string(info.ID)) == "" || len(mediaKey) != 32 {
		return ErrHistoryRecoveryInvalid
	}
	chat, err := normalizeMessageSource(info.MessageSource)
	if err != nil {
		return err
	}
	sender, err := NormalizeOneToOneJID(info.Sender.ToNonAD())
	if err != nil {
		return err
	}
	info.Chat = chat.JID
	info.Sender = sender.JID
	info.IsGroup = false
	now := time.Now()
	a.recoveryMu.Lock()
	defer a.recoveryMu.Unlock()
	if a.mediaRetries == nil {
		a.mediaRetries = make(map[string]mediaRetrySecret)
	}
	for key, entry := range a.mediaRetries {
		if !entry.expiresAt.After(now) {
			delete(a.mediaRetries, key)
		}
	}
	cacheKey := mediaRetryCacheKey(sessionID, info.ID)
	if _, replacing := a.mediaRetries[cacheKey]; !replacing && len(a.mediaRetries) >= maxMediaRetryEntries {
		return ErrRecoveryLimitExceeded
	}
	a.mediaRetries[cacheKey] = mediaRetrySecret{
		info: info, mediaKey: append([]byte(nil), mediaKey...), expiresAt: now.Add(mediaRetryTTL),
	}
	return nil
}

func (a *WhatsMeowAdapter) RetryMedia(
	ctx context.Context,
	sessionID string,
	payload domain.MediaRetryPayload,
) error {
	client, chat, err := a.readyRecoveryRecipient(sessionID, payload.To)
	if err != nil {
		return err
	}
	retryClient, ok := client.(mediaRetryClient)
	if !ok {
		return errors.New("WhatsApp client does not support media retry")
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" || strings.TrimSpace(payload.Sender) == "" {
		return ErrHistoryRecoveryInvalid
	}
	sender, err := NormalizeOneToOneAddress(payload.Sender)
	if err != nil {
		return err
	}
	var info types.MessageInfo
	var mediaKey []byte
	if a.recoveryStore != nil {
		state, stateErr := a.recoveryStore.BeginMediaRetry(
			ctx, sessionID, payload.TargetMessageID, time.Now().UTC(),
		)
		if stateErr != nil {
			if errors.Is(stateErr, domain.ErrNotFound) {
				return ErrMediaRetryStateMissing
			}
			return stateErr
		}
		var envelope mediaRetryEnvelope
		if json.Unmarshal(state.Descriptor, &envelope) != nil || len(envelope.Message) == 0 {
			return ErrMediaRetryStateMissing
		}
		if envelope.Chat != chat.String() || envelope.IsFromMe != payload.FromMe {
			return ErrMediaRetryStateMissing
		}
		if envelope.Sender != sender.JID.String() {
			return ErrMediaRetryStateMissing
		}
		var message waE2E.Message
		if proto.Unmarshal(envelope.Message, &message) != nil {
			return ErrMediaRetryStateMissing
		}
		media := downloadableMessage(&message)
		keyed, ok := media.(interface{ GetMediaKey() []byte })
		if !ok || len(keyed.GetMediaKey()) != 32 {
			return ErrMediaRetryStateMissing
		}
		mediaKey = append([]byte(nil), keyed.GetMediaKey()...)
		info = types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat: chat, Sender: sender.JID, IsFromMe: envelope.IsFromMe,
			},
			ID: types.MessageID(payload.TargetMessageID),
		}
	} else {
		secret, ok := a.mediaRetrySecret(sessionID, types.MessageID(payload.TargetMessageID))
		if !ok || secret.info.Chat.ToNonAD() != chat || secret.info.IsFromMe != payload.FromMe {
			return ErrMediaRetryStateMissing
		}
		if secret.info.Sender.ToNonAD() != sender.JID {
			return ErrMediaRetryStateMissing
		}
		info = secret.info
		mediaKey = secret.mediaKey
	}
	return retryClient.SendMediaRetryReceipt(ctx, &info, mediaKey)
}

// MediaRetrySecret is intentionally package-internal data surfaced through an
// adapter method only for the event bridge's decrypt-and-forget flow.
func (a *WhatsMeowAdapter) MediaRetrySecret(
	sessionID string,
	messageID types.MessageID,
) ([]byte, bool) {
	secret, ok := a.mediaRetrySecret(sessionID, messageID)
	if !ok {
		return nil, false
	}
	return append([]byte(nil), secret.mediaKey...), true
}

func (a *WhatsMeowAdapter) ForgetMediaRetry(sessionID string, messageID types.MessageID) {
	a.recoveryMu.Lock()
	delete(a.mediaRetries, mediaRetryCacheKey(sessionID, messageID))
	a.recoveryMu.Unlock()
}

func (a *WhatsMeowAdapter) mediaRetrySecret(
	sessionID string,
	messageID types.MessageID,
) (mediaRetrySecret, bool) {
	a.recoveryMu.Lock()
	defer a.recoveryMu.Unlock()
	key := mediaRetryCacheKey(sessionID, messageID)
	secret, ok := a.mediaRetries[key]
	if ok && !secret.expiresAt.After(time.Now()) {
		delete(a.mediaRetries, key)
		return mediaRetrySecret{}, false
	}
	if !ok {
		return mediaRetrySecret{}, false
	}
	secret.mediaKey = append([]byte(nil), secret.mediaKey...)
	return secret, true
}

func (a *WhatsMeowAdapter) clearMediaRetrySession(sessionID string) {
	prefix := sessionID + "\x00"
	a.recoveryMu.Lock()
	for key := range a.mediaRetries {
		if strings.HasPrefix(key, prefix) {
			delete(a.mediaRetries, key)
		}
	}
	a.recoveryMu.Unlock()
}

func mediaRetryCacheKey(sessionID string, messageID types.MessageID) string {
	return sessionID + "\x00" + string(messageID)
}

func (a *WhatsMeowAdapter) DownloadLinkThumbnail(
	ctx context.Context,
	sessionID string,
	message *waE2E.ExtendedTextMessage,
) ([]byte, error) {
	if message == nil || len(message.GetThumbnailDirectPath()) == 0 ||
		len(message.GetThumbnailDirectPath()) > 4096 || len(message.GetThumbnailSHA256()) != 32 ||
		len(message.GetThumbnailEncSHA256()) != 32 || len(message.GetMediaKey()) != 32 {
		return nil, ErrHistoryRecoveryInvalid
	}
	client, err := a.readyRecoveryClient(sessionID)
	if err != nil {
		return nil, err
	}
	downloader, ok := client.(thumbnailClient)
	if !ok {
		return nil, errors.New("WhatsApp client does not support thumbnail download")
	}
	data, err := downloader.DownloadThumbnail(ctx, message)
	if err != nil {
		return nil, err
	}
	if len(data) > maxThumbnailBytes {
		return nil, ErrRecoveryLimitExceeded
	}
	return data, nil
}

func (a *WhatsMeowAdapter) FetchStickerPackMetadata(
	ctx context.Context,
	sessionID string,
	packID string,
) (StickerPackSummary, error) {
	if !stickerPackIDPattern.MatchString(packID) {
		return StickerPackSummary{}, ErrHistoryRecoveryInvalid
	}
	client, err := a.readyRecoveryClient(sessionID)
	if err != nil {
		return StickerPackSummary{}, err
	}
	fetcher, ok := client.(stickerPackClient)
	if !ok {
		return StickerPackSummary{}, errors.New("WhatsApp client does not support sticker packs")
	}
	pack, err := fetcher.FetchStickerPack(ctx, packID)
	if err != nil {
		return StickerPackSummary{}, err
	}
	if pack == nil || len(pack.Stickers) > maxStickerPackItems {
		return StickerPackSummary{}, ErrRecoveryLimitExceeded
	}
	summary := StickerPackSummary{
		ID: pack.StickerPackID, Name: pack.Name, Publisher: pack.Publisher,
		Description: pack.Description, Animated: pack.Animated != 0, Lottie: pack.Lottie != 0,
		Stickers: make([]StickerSummary, 0, len(pack.Stickers)),
	}
	for _, sticker := range pack.Stickers {
		if sticker == nil || sticker.FileSize < 0 || sticker.FileSize > maxStickerBytes {
			return StickerPackSummary{}, ErrRecoveryLimitExceeded
		}
		summary.Stickers = append(summary.Stickers, StickerSummary{
			MIMEType: sticker.MimeType, SizeBytes: sticker.FileSize,
			Width: sticker.Width, Height: sticker.Height,
			Emojis:            append([]string(nil), sticker.Emojis...),
			AccessibilityText: sticker.AccessibilityText,
		})
	}
	return summary, nil
}

func (a *WhatsMeowAdapter) readyRecoveryClient(sessionID string) (WhatsMeowClient, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, err
	}
	if !client.IsConnected() {
		return nil, ErrSessionNotConnected
	}
	return client, nil
}

func (a *WhatsMeowAdapter) readyRecoveryRecipient(
	sessionID string,
	address string,
) (WhatsMeowClient, types.JID, error) {
	client, err := a.readyRecoveryClient(sessionID)
	if err != nil {
		return nil, types.JID{}, err
	}
	normalized, err := NormalizeOneToOneAddress(address)
	if err != nil {
		return nil, types.JID{}, err
	}
	return client, normalized.JID, nil
}
