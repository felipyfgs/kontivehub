package protocol

import (
	"context"
	"errors"
	"strings"
	"sync"
	"sync/atomic"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/spool"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

type WhatsMeowClient interface {
	Connect() error
	Disconnect()
	IsConnected() bool
	Logout(context.Context) error
	GetQRChannel(context.Context) (<-chan whatsmeow.QRChannelItem, error)
	SendMessage(context.Context, types.JID, *waE2E.Message, ...whatsmeow.SendRequestExtra) (whatsmeow.SendResponse, error)
	Upload(context.Context, []byte, whatsmeow.MediaType) (whatsmeow.UploadResponse, error)
}

func (a *WhatsMeowAdapter) SendMedia(
	ctx context.Context,
	sessionID, address, caption, filename, mimeType, providerMessageID string,
	content []byte,
) error {
	client, jid, err := a.readyRecipient(sessionID, address)
	if err != nil {
		return err
	}
	if len(content) == 0 {
		return errors.New("media is empty")
	}
	mediaType := whatsmeowMediaType(mimeType)
	uploaded, err := client.Upload(ctx, content, mediaType)
	if err != nil {
		return err
	}
	message := outboundMediaMessage(uploaded, mediaType, caption, filename, mimeType)
	_, err = client.SendMessage(
		ctx, jid, message,
		whatsmeow.SendRequestExtra{ID: types.MessageID(providerMessageID)},
	)
	return err
}

func whatsmeowMediaType(mimeType string) whatsmeow.MediaType {
	switch {
	case strings.HasPrefix(strings.ToLower(strings.TrimSpace(mimeType)), "image/"):
		return whatsmeow.MediaImage
	case strings.HasPrefix(strings.ToLower(strings.TrimSpace(mimeType)), "audio/"):
		return whatsmeow.MediaAudio
	case strings.HasPrefix(strings.ToLower(strings.TrimSpace(mimeType)), "video/"):
		return whatsmeow.MediaVideo
	default:
		return whatsmeow.MediaDocument
	}
}

func outboundMediaMessage(
	uploaded whatsmeow.UploadResponse,
	mediaType whatsmeow.MediaType,
	caption, filename, mimeType string,
) *waE2E.Message {
	switch mediaType {
	case whatsmeow.MediaImage:
		return &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(mimeType), Caption: proto.String(caption),
		}}
	case whatsmeow.MediaAudio:
		return &waE2E.Message{AudioMessage: &waE2E.AudioMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(mimeType),
		}}
	case whatsmeow.MediaVideo:
		return &waE2E.Message{VideoMessage: &waE2E.VideoMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(mimeType), Caption: proto.String(caption),
		}}
	default:
		return &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{
			URL: proto.String(uploaded.URL), DirectPath: proto.String(uploaded.DirectPath),
			MediaKey: uploaded.MediaKey, FileEncSHA256: uploaded.FileEncSHA256,
			FileSHA256: uploaded.FileSHA256, FileLength: proto.Uint64(uploaded.FileLength),
			Mimetype: proto.String(mimeType), FileName: proto.String(filename), Caption: proto.String(caption),
		}}
	}
}

var _ WhatsMeowClient = (*whatsmeow.Client)(nil)

type ClientResolver interface {
	Resolve(sessionID string) (WhatsMeowClient, error)
}

type WhatsMeowAdapter struct {
	clients                       ClientResolver
	settings                      ClientSettings
	stateMu                       sync.RWMutex
	passive                       map[string]bool
	recoveryMu                    sync.Mutex
	mediaRetries                  map[string]mediaRetrySecret
	recoveryStore                 store.Store
	stickerStore                  store.Store
	stickerSpool                  *spool.Store
	stickerMaxBytes               int64
	profilePictureQueriesInFlight atomic.Int64
}

func (a *WhatsMeowAdapter) WithRecoveryStore(persistence store.Store) *WhatsMeowAdapter {
	a.recoveryStore = persistence
	return a
}

func (a *WhatsMeowAdapter) WithStickerMaterialization(
	persistence store.Store,
	mediaSpool *spool.Store,
	maxBytes int64,
) *WhatsMeowAdapter {
	a.stickerStore = persistence
	a.stickerSpool = mediaSpool
	a.stickerMaxBytes = maxBytes
	return a
}

func NewWhatsMeowAdapter(clients ClientResolver, settings ...ClientSettings) *WhatsMeowAdapter {
	return newWhatsMeowAdapter(clients, settings...)
}

func (a *WhatsMeowAdapter) Logout(ctx context.Context, sessionID string) error {
	if !a.HasCredentials(sessionID) {
		return a.ForgetSession(ctx, sessionID)
	}
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return err
	}
	if err := client.Logout(ctx); err != nil {
		if !a.HasCredentials(sessionID) {
			return a.ForgetSession(ctx, sessionID)
		}
		return ErrSessionLogout
	}
	return a.ForgetSession(ctx, sessionID)
}

func (a *WhatsMeowAdapter) Disconnect(sessionID string) {
	if client, ok := a.peekSessionClient(sessionID); ok {
		client.Disconnect()
	}
	if releaser, ok := a.clients.(ClientReleaser); ok {
		releaser.Release(sessionID)
	}
	a.clearSessionState(sessionID)
}

func (a *WhatsMeowAdapter) SendText(
	ctx context.Context,
	sessionID, address, text, providerMessageID string,
) error {
	client, jid, err := a.readyRecipient(sessionID, address)
	if err != nil {
		return err
	}
	_, err = client.SendMessage(
		ctx,
		jid,
		&waE2E.Message{Conversation: proto.String(text)},
		whatsmeow.SendRequestExtra{ID: types.MessageID(providerMessageID)},
	)
	return err
}

func (a *WhatsMeowAdapter) readyRecipient(sessionID, address string) (WhatsMeowClient, types.JID, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, types.JID{}, err
	}
	if !client.IsConnected() {
		return nil, types.JID{}, errors.New("WhatsApp session is not connected")
	}
	normalized, err := NormalizeOneToOneAddress(address)
	if err != nil {
		return nil, types.JID{}, err
	}
	return client, normalized.JID, nil
}
