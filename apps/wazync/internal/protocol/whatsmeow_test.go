package protocol

import (
	"context"
	"io"
	"testing"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

type fakeResolver struct {
	client      *fakeClient
	paired      bool
	resolveN    int
	peekMissing bool
}

func (r *fakeResolver) Resolve(string) (WhatsMeowClient, error) {
	r.resolveN++
	return r.client, nil
}
func (r *fakeResolver) Peek(string) (WhatsMeowClient, bool) {
	if r.peekMissing || r.client == nil {
		return nil, false
	}
	return r.client, true
}
func (r *fakeResolver) HasDevice(string) bool { return r.paired }
func (r *fakeResolver) Release(string)        {}

type fakeClient struct {
	connected             bool
	loggedIn              bool
	ready                 bool
	loginOnConnect        bool
	connectCalls          int
	connectContextCalls   int
	waitForConnectionCall int
	resetCalls            int
	connectErr            error
	connectWait           <-chan struct{}
	disconnectSignal      chan struct{}
	qrUpdates             <-chan whatsmeow.QRChannelItem
	qrError               error
	pairPhoneCode         string
	pairPhoneError        error
	pairedPhone           string
	showPairingPush       bool
	passkeyResponse       *types.WebAuthnResponse
	passkeyResponseError  error
	passkeyConfirmCalls   int
	passkeyConfirmError   error
	passive               bool
	passiveError          error
	to                    types.JID
	message               *waE2E.Message
	extra                 whatsmeow.SendRequestExtra
	uploadType            whatsmeow.MediaType
	streamed              bool
}

func (c *fakeClient) UploadReader(
	ctx context.Context,
	plaintext io.Reader,
	_ io.ReadWriteSeeker,
	mediaType whatsmeow.MediaType,
) (whatsmeow.UploadResponse, error) {
	c.streamed = true
	content, err := io.ReadAll(plaintext)
	if err != nil {
		return whatsmeow.UploadResponse{}, err
	}
	return c.Upload(ctx, content, mediaType)
}

func (c *fakeClient) BuildPollCreation(name string, options []string, selectable int) *waE2E.Message {
	items := make([]*waE2E.PollCreationMessage_Option, 0, len(options))
	for _, option := range options {
		items = append(items, &waE2E.PollCreationMessage_Option{OptionName: &option})
	}
	count := uint32(selectable)
	return &waE2E.Message{PollCreationMessage: &waE2E.PollCreationMessage{
		Name: &name, Options: items, SelectableOptionsCount: &count,
	}}
}

func (c *fakeClient) Upload(_ context.Context, content []byte, mediaType whatsmeow.MediaType) (whatsmeow.UploadResponse, error) {
	c.uploadType = mediaType
	return whatsmeow.UploadResponse{
		URL: "https://upload.invalid/document", DirectPath: "/document", MediaKey: []byte("key"),
		FileEncSHA256: []byte("encrypted"), FileSHA256: []byte("plain"), FileLength: uint64(len(content)),
	}, nil
}

func (c *fakeClient) Connect() error {
	c.connectCalls++
	if c.connectWait != nil {
		<-c.connectWait
	}
	if c.disconnectSignal != nil {
		select {
		case <-c.disconnectSignal:
			return context.Canceled
		default:
		}
	}
	if c.connectErr != nil {
		return c.connectErr
	}
	c.connected = true
	if c.loginOnConnect {
		c.loggedIn = true
	}
	return nil
}
func (c *fakeClient) Disconnect() {
	c.connected = false
	if c.disconnectSignal != nil {
		select {
		case <-c.disconnectSignal:
		default:
			close(c.disconnectSignal)
		}
	}
}
func (c *fakeClient) IsConnected() bool {
	return c.connected
}

func TestWhatsMeowAdapterUploadsDocumentWithStableProviderMessageID(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	adapter := NewWhatsMeowAdapter(&fakeResolver{client: client})
	if err := adapter.SendMedia(
		t.Context(), "session-0001", "+5511999991234", "Segue", "guia.pdf",
		"application/pdf", "provider-document-0001", []byte("%PDF"),
	); err != nil {
		t.Fatalf("send document: %v", err)
	}
	document := client.message.GetDocumentMessage()
	if document == nil || document.GetFileName() != "guia.pdf" || document.GetCaption() != "Segue" {
		t.Fatalf("unexpected document message: %+v", document)
	}
	if client.extra.ID != "provider-document-0001" {
		t.Fatalf("provider ID changed: %s", client.extra.ID)
	}
	if client.uploadType != whatsmeow.MediaDocument {
		t.Fatalf("unexpected upload type: %s", client.uploadType)
	}
}

func TestWhatsMeowAdapterMapsNativeMediaByMIMEType(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name      string
		mimeType  string
		mediaType whatsmeow.MediaType
		assert    func(*testing.T, *waE2E.Message)
	}{
		{
			name: "image", mimeType: "image/jpeg", mediaType: whatsmeow.MediaImage,
			assert: func(t *testing.T, message *waE2E.Message) {
				t.Helper()
				if image := message.GetImageMessage(); image == nil || image.GetMimetype() != "image/jpeg" || image.GetCaption() != "Legenda" {
					t.Fatalf("unexpected image message: %+v", image)
				}
			},
		},
		{
			name: "audio", mimeType: "audio/ogg", mediaType: whatsmeow.MediaAudio,
			assert: func(t *testing.T, message *waE2E.Message) {
				t.Helper()
				if audio := message.GetAudioMessage(); audio == nil || audio.GetMimetype() != "audio/ogg" {
					t.Fatalf("unexpected audio message: %+v", audio)
				}
			},
		},
		{
			name: "video", mimeType: "video/mp4", mediaType: whatsmeow.MediaVideo,
			assert: func(t *testing.T, message *waE2E.Message) {
				t.Helper()
				if video := message.GetVideoMessage(); video == nil || video.GetMimetype() != "video/mp4" || video.GetCaption() != "Legenda" {
					t.Fatalf("unexpected video message: %+v", video)
				}
			},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			client := &fakeClient{connected: true}
			adapter := NewWhatsMeowAdapter(&fakeResolver{client: client})
			if err := adapter.SendMedia(
				t.Context(), "session-0001", "+5511999991234", "Legenda", "arquivo.bin",
				test.mimeType, "provider-media-0001", []byte("media"),
			); err != nil {
				t.Fatalf("send media: %v", err)
			}
			if client.uploadType != test.mediaType {
				t.Fatalf("upload type: got %q want %q", client.uploadType, test.mediaType)
			}
			test.assert(t, client.message)
			if client.extra.ID != "provider-media-0001" {
				t.Fatalf("provider ID changed: %s", client.extra.ID)
			}
		})
	}
}
func (c *fakeClient) Logout(context.Context) error { c.connected = false; return nil }
func (c *fakeClient) GetQRChannel(context.Context) (<-chan whatsmeow.QRChannelItem, error) {
	if c.qrError != nil {
		return nil, c.qrError
	}
	if c.qrUpdates != nil {
		return c.qrUpdates, nil
	}
	updates := make(chan whatsmeow.QRChannelItem)
	close(updates)
	return updates, nil
}
func (c *fakeClient) SendMessage(
	_ context.Context,
	to types.JID,
	message *waE2E.Message,
	extra ...whatsmeow.SendRequestExtra,
) (whatsmeow.SendResponse, error) {
	c.to = to
	c.message = message
	c.extra = extra[0]
	return whatsmeow.SendResponse{}, nil
}

func TestWhatsMeowAdapterUsesStableProviderMessageID(t *testing.T) {
	t.Parallel()
	client := &fakeClient{connected: true}
	adapter := NewWhatsMeowAdapter(&fakeResolver{client: client})
	if err := adapter.SendText(t.Context(), "session-0001", "+5511999991234", "Olá", "provider-message-0001"); err != nil {
		t.Fatalf("send text: %v", err)
	}
	if client.to.User != "5511999991234" || client.to.Server != types.DefaultUserServer {
		t.Fatalf("unexpected recipient JID: %s", client.to.String())
	}
	if client.message.GetConversation() != "Olá" {
		t.Fatalf("unexpected message: %q", client.message.GetConversation())
	}
	if client.extra.ID != "provider-message-0001" {
		t.Fatalf("provider ID changed: %s", client.extra.ID)
	}
}
