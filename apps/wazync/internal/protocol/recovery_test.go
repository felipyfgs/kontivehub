package protocol

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waCommon"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waWeb"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

type fakeRecoveryClient struct {
	*fakeClient
	historyRequestInfo  *types.MessageInfo
	historyRequestCount int
	peerMessage         *waE2E.Message
	history             *waHistorySync.HistorySync
	downloadErr         error
	downloadSynchronous bool
	deleteType          whatsmeow.MediaType
	deletePath          string
	serverErrorID       types.MessageID
	serverErrorKey      []byte
	parseCalls          int
	retryInfo           *types.MessageInfo
	retryKey            []byte
	retryErr            error
	thumbnail           []byte
	stickerPack         *types.StickerPack
}

type countingMediaRetryClient struct {
	*fakeRecoveryClient
	mu       sync.Mutex
	receipts int
}

func (c *countingMediaRetryClient) SendMediaRetryReceipt(
	_ context.Context,
	_ *types.MessageInfo,
	_ []byte,
) error {
	c.mu.Lock()
	c.receipts++
	c.mu.Unlock()
	return nil
}

func (c *countingMediaRetryClient) receiptCount() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.receipts
}

type barrierMediaRetryStore struct {
	store.Store
	reached chan<- struct{}
	release <-chan struct{}
}

type rejectingMediaRetryClaimStore struct {
	store.Store
	claimAttempts int
}

func (s *rejectingMediaRetryClaimStore) CompareAndBeginMediaRetry(
	_ context.Context,
	expected domain.MediaRetryState,
	_ time.Time,
) (domain.MediaRetryState, bool, error) {
	s.claimAttempts++
	return expected, false, nil
}

func (s barrierMediaRetryStore) GetMediaRetryState(
	ctx context.Context,
	sessionID, messageID string,
) (domain.MediaRetryState, error) {
	state, err := s.Store.GetMediaRetryState(ctx, sessionID, messageID)
	if err != nil {
		return state, err
	}
	s.reached <- struct{}{}
	select {
	case <-s.release:
		return state, nil
	case <-ctx.Done():
		return domain.MediaRetryState{}, ctx.Err()
	}
}

func newFakeRecoveryClient() *fakeRecoveryClient {
	return &fakeRecoveryClient{fakeClient: &fakeClient{connected: true}}
}

func (c *fakeRecoveryClient) BuildHistorySyncRequest(info *types.MessageInfo, count int) *waE2E.Message {
	copyInfo := *info
	c.historyRequestInfo = &copyInfo
	c.historyRequestCount = count
	return &waE2E.Message{Conversation: proto.String("history-request")}
}

func (c *fakeRecoveryClient) SendPeerMessage(
	_ context.Context,
	message *waE2E.Message,
) (whatsmeow.SendResponse, error) {
	c.peerMessage = message
	return whatsmeow.SendResponse{}, nil
}

func (c *fakeRecoveryClient) DownloadHistorySync(
	_ context.Context,
	_ *waE2E.HistorySyncNotification,
	synchronous bool,
) (*waHistorySync.HistorySync, error) {
	c.downloadSynchronous = synchronous
	return c.history, c.downloadErr
}

func (c *fakeRecoveryClient) DeleteMedia(
	_ context.Context,
	mediaType whatsmeow.MediaType,
	directPath string,
	_ []byte,
	_ string,
) error {
	c.deleteType = mediaType
	c.deletePath = directPath
	return nil
}

func (c *fakeRecoveryClient) ParseWebMessage(
	chat types.JID,
	message *waWeb.WebMessageInfo,
) (*events.Message, error) {
	c.parseCalls++
	if message == nil || message.GetKey().GetID() == "invalid" {
		return nil, errors.New("invalid web message")
	}
	return &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{Chat: chat, Sender: chat},
			ID:            types.MessageID(message.GetKey().GetID()),
			Timestamp:     time.Unix(int64(message.GetMessageTimestamp()), 0),
		},
		Message: message.GetMessage(),
	}, nil
}

func (c *fakeRecoveryClient) SendHistorySyncServerErrorReceipt(
	_ context.Context,
	messageID types.MessageID,
	mediaKey []byte,
) error {
	c.serverErrorID = messageID
	c.serverErrorKey = append([]byte(nil), mediaKey...)
	return nil
}

func (c *fakeRecoveryClient) SendMediaRetryReceipt(
	_ context.Context,
	info *types.MessageInfo,
	mediaKey []byte,
) error {
	copyInfo := *info
	c.retryInfo = &copyInfo
	c.retryKey = append([]byte(nil), mediaKey...)
	return c.retryErr
}

func (c *fakeRecoveryClient) DownloadThumbnail(
	_ context.Context,
	_ whatsmeow.DownloadableThumbnail,
) ([]byte, error) {
	return append([]byte(nil), c.thumbnail...), nil
}

func (c *fakeRecoveryClient) FetchStickerPack(
	_ context.Context,
	_ string,
) (*types.StickerPack, error) {
	return c.stickerPack, nil
}

func TestRequestHistorySyncUsesBoundedOneToOneCursor(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	adapter := NewWhatsMeowAdapter(&fakeResolver{client: client.fakeClient})
	adapter.clients = recoveryResolver{client: client}

	payload := domain.HistorySyncPayload{
		To: "+5511999991234", LastMessageID: "provider-cursor-0001",
		LastMessageFrom: "+5511999991234", LastMessageTimestamp: 1_700_000_000,
		LastMessageFromMe: true, Count: MaxHistoryRequestMessages,
	}
	if err := adapter.RequestHistorySync(t.Context(), "session-history-0001", payload); err != nil {
		t.Fatalf("request history sync: %v", err)
	}
	if client.historyRequestInfo == nil || client.historyRequestInfo.Chat.User != "5511999991234" ||
		client.historyRequestInfo.ID != "provider-cursor-0001" ||
		client.historyRequestInfo.Timestamp.Unix() != payload.LastMessageTimestamp ||
		!client.historyRequestInfo.IsFromMe || client.historyRequestCount != MaxHistoryRequestMessages ||
		client.peerMessage == nil {
		t.Fatalf("unexpected history request: info=%+v count=%d", client.historyRequestInfo, client.historyRequestCount)
	}

	payload.Count = MaxHistoryRequestMessages + 1
	if err := adapter.RequestHistorySync(t.Context(), "session-history-0001", payload); !errors.Is(err, ErrHistoryRecoveryInvalid) {
		t.Fatalf("request above official limit must fail: %v", err)
	}
	payload.Count = 1
	payload.To = "120363000000000000@g.us"
	if err := adapter.RequestHistorySync(t.Context(), "session-history-0001", payload); !errors.Is(err, ErrRecipientScopeNotAllowed) {
		t.Fatalf("group history request must fail before peer send: %v", err)
	}
}

func TestRecoverHistorySyncFiltersScopeDeduplicatesParsesAndDeletes(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	client.history = &waHistorySync.HistorySync{
		SyncType:   waHistorySync.HistorySync_ON_DEMAND.Enum(),
		ChunkOrder: proto.Uint32(3), Progress: proto.Uint32(75),
		Conversations: []*waHistorySync.Conversation{
			historyConversation("5511999991234@s.whatsapp.net", "provider-history-0001", "provider-history-0001", "invalid"),
			historyConversation("120363000000000000@g.us", "provider-group-0001"),
			historyConversation("120363000000000001@newsletter", "provider-newsletter-0001"),
		},
	}
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client})
	notification := &waE2E.HistorySyncNotification{
		FileLength: proto.Uint64(2048), DirectPath: proto.String("/history/opaque"),
		FileEncSHA256: bytesOf(32, 0x21), EncHandle: proto.String("opaque-handle"),
	}
	batch, err := adapter.RecoverHistorySync(
		t.Context(), "session-history-0002", notification, "+5511999991234",
	)
	if err != nil {
		t.Fatalf("recover history sync: %v", err)
	}
	if !client.downloadSynchronous || client.deleteType != whatsmeow.MediaHistory || client.deletePath != "/history/opaque" {
		t.Fatalf("history blob was not synchronously stored and deleted: %+v", client)
	}
	if len(batch.Messages) != 1 || batch.Messages[0].Info.ID != "provider-history-0001" ||
		batch.DuplicateMessages != 1 || batch.InvalidMessages != 1 || batch.RejectedScope != 2 ||
		client.parseCalls != 2 {
		t.Fatalf("unexpected filtered history batch: %+v parse_calls=%d", batch, client.parseCalls)
	}
}

func TestRecoverHistorySyncRequestsReuploadAfterDownloadFailure(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	client.downloadErr = errors.New("history media returned 410")
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client})
	mediaKey := bytesOf(32, 0x31)
	_, err := adapter.RecoverHistorySync(t.Context(), "session-history-0003", &waE2E.HistorySyncNotification{
		FileLength: proto.Uint64(1024), OriginalMessageID: proto.String("provider-history-notification-0001"),
		MediaKey: mediaKey,
	}, "")
	if err == nil || client.serverErrorID != "provider-history-notification-0001" ||
		string(client.serverErrorKey) != string(mediaKey) || client.deletePath != "" {
		t.Fatalf("download failure did not request safe reupload: err=%v id=%s", err, client.serverErrorID)
	}
}

func TestMediaRetryUsesBoundedInternalSecretInsteadOfContractKey(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client})
	chat := types.NewJID("5511999991234", types.DefaultUserServer)
	key := bytesOf(32, 0x41)
	info := types.MessageInfo{
		MessageSource: types.MessageSource{Chat: chat, Sender: chat},
		ID:            "provider-media-0001",
	}
	if err := adapter.RememberMediaRetry("session-retry-0001", info, key); err != nil {
		t.Fatalf("remember media retry: %v", err)
	}
	key[0] = 0xff
	if err := adapter.RetryMedia(t.Context(), "session-retry-0001", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-media-0001",
		Sender: "+5511999991234", FromMe: false,
	}); err != nil {
		t.Fatalf("retry media: %v", err)
	}
	if client.retryInfo == nil || client.retryInfo.ID != "provider-media-0001" ||
		len(client.retryKey) != 32 || client.retryKey[0] != 0x41 {
		t.Fatalf("media retry did not use copied internal secret: info=%+v key=%x", client.retryInfo, client.retryKey)
	}
	adapter.clearSessionState("session-retry-0001")
	if err := adapter.RetryMedia(t.Context(), "session-retry-0001", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-media-0001", Sender: "+5511999991234",
	}); !errors.Is(err, ErrMediaRetryStateMissing) {
		t.Fatalf("forgotten media secret must fail closed: %v", err)
	}
}

func TestMediaRetryCommandLoadsDurableDescriptorAfterAdapterRestart(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	persistence := store.NewMemory()
	message := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
		MediaKey: bytesOf(32, 0x71), FileSHA256: bytesOf(32, 0x72),
	}}
	descriptor, _ := proto.Marshal(message)
	envelope, _ := json.Marshal(mediaRetryEnvelope{
		Message:  descriptor,
		Chat:     "5511999991234@s.whatsapp.net",
		Sender:   "5511999991234@s.whatsapp.net",
		IsFromMe: false,
	})
	if err := persistence.PutMediaRetryState(t.Context(), domain.MediaRetryState{
		SessionID: "session-retry-durable", MessageID: "provider-retry-durable",
		Descriptor: envelope,
	}); err != nil {
		t.Fatalf("persist retry descriptor: %v", err)
	}

	// A fresh adapter has no process-local retry cache and proves restart
	// recovery goes through the durable encrypted-store contract.
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client}).WithRecoveryStore(persistence)
	if err := adapter.RetryMedia(t.Context(), "session-retry-durable", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-durable",
		Sender: "+5511999991234", FromMe: false,
	}); err != nil {
		t.Fatalf("retry media after adapter restart: %v", err)
	}
	if client.retryInfo == nil || client.retryInfo.ID != "provider-retry-durable" ||
		client.retryInfo.Chat.User != "5511999991234" ||
		len(client.retryKey) != 32 || client.retryKey[0] != 0x71 {
		t.Fatalf("durable retry descriptor was not used safely: info=%+v key=%x",
			client.retryInfo, client.retryKey)
	}
	state, err := persistence.GetMediaRetryState(
		t.Context(), "session-retry-durable", "provider-retry-durable",
	)
	if err != nil || state.Generation != 1 || state.Attempts != 1 {
		t.Fatalf("retry generation/attempt not advanced: state=%+v err=%v", state, err)
	}
	providerErr := errors.New("provider receipt failed")
	client.retryErr = providerErr
	err = adapter.RetryMedia(t.Context(), "session-retry-durable", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-durable",
		Sender: "+5511999991234", FromMe: false,
	})
	var receiptErr *MediaRetryReceiptError
	if !errors.As(err, &receiptErr) || receiptErr.Claim.Generation != 2 || receiptErr.Claim.Attempt != 2 {
		t.Fatalf("receipt failure lost durable claim: err=%v claim=%+v", err, receiptErr)
	}
	if !errors.Is(err, providerErr) {
		t.Fatalf("receipt failure did not preserve provider cause: %v", err)
	}
}

func TestConcurrentMediaRetryCommandsClaimOneGenerationAndSendOneReceipt(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	message := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
		MediaKey: bytesOf(32, 0x79), FileSHA256: bytesOf(32, 0x7a),
	}}
	descriptor, _ := proto.Marshal(message)
	envelope, _ := json.Marshal(mediaRetryEnvelope{
		Message: descriptor, Chat: "5511999991234@s.whatsapp.net",
		Sender: "5511999991234@s.whatsapp.net", IsFromMe: false,
	})
	if err := persistence.PutMediaRetryState(t.Context(), domain.MediaRetryState{
		SessionID: "session-retry-concurrent", MessageID: "provider-retry-concurrent",
		Descriptor: envelope,
	}); err != nil {
		t.Fatalf("persist concurrent retry descriptor: %v", err)
	}

	reached := make(chan struct{}, 2)
	release := make(chan struct{})
	guardedStore := barrierMediaRetryStore{
		Store: persistence, reached: reached, release: release,
	}
	client := &countingMediaRetryClient{fakeRecoveryClient: newFakeRecoveryClient()}
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client}).WithRecoveryStore(guardedStore)
	payload := domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-concurrent",
		ExpectedDirection: "INBOUND",
	}
	errs := make(chan error, 2)
	for range 2 {
		go func() {
			errs <- adapter.RetryMedia(t.Context(), "session-retry-concurrent", payload)
		}()
	}
	for range 2 {
		select {
		case <-reached:
		case <-time.After(time.Second):
			t.Fatal("concurrent retries did not reach the durable-state barrier")
		}
	}
	close(release)
	succeeded := 0
	claimLost := 0
	for range 2 {
		select {
		case err := <-errs:
			switch {
			case err == nil:
				succeeded++
			case errors.Is(err, ErrMediaRetryClaimLost):
				claimLost++
			default:
				t.Fatalf("concurrent media retry failed: %v", err)
			}
		case <-time.After(time.Second):
			t.Fatal("concurrent media retry did not complete after barrier release")
		}
	}
	if succeeded != 1 || claimLost != 1 {
		t.Fatalf("concurrent retry outcomes: success=%d claim_lost=%d; want 1/1", succeeded, claimLost)
	}

	if got := client.receiptCount(); got != 1 {
		t.Fatalf("concurrent retries sent %d receipts; want 1", got)
	}
	state, err := persistence.GetMediaRetryState(
		t.Context(), "session-retry-concurrent", "provider-retry-concurrent",
	)
	if err != nil || state.Generation != 1 || state.Attempts != 1 {
		t.Fatalf("concurrent retry state=%+v err=%v; want generation/attempt 1", state, err)
	}
}

func TestMediaRetryReportsLostClaimAfterBoundedUnchangedRejections(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	message := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
		MediaKey: bytesOf(32, 0x7b), FileSHA256: bytesOf(32, 0x7c),
	}}
	descriptor, _ := proto.Marshal(message)
	envelope, _ := json.Marshal(mediaRetryEnvelope{
		Message: descriptor, Chat: "5511999991234@s.whatsapp.net",
		Sender: "5511999991234@s.whatsapp.net", IsFromMe: false,
	})
	if err := persistence.PutMediaRetryState(t.Context(), domain.MediaRetryState{
		SessionID: "session-retry-rejected", MessageID: "provider-retry-rejected",
		Descriptor: envelope,
	}); err != nil {
		t.Fatalf("persist rejected retry descriptor: %v", err)
	}
	rejectingStore := &rejectingMediaRetryClaimStore{Store: persistence}
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: newFakeRecoveryClient()}).WithRecoveryStore(rejectingStore)
	err := adapter.RetryMedia(t.Context(), "session-retry-rejected", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-rejected",
		ExpectedDirection: "INBOUND",
	})
	if !errors.Is(err, ErrMediaRetryClaimLost) {
		t.Fatalf("bounded claim rejection error=%v; want %v", err, ErrMediaRetryClaimLost)
	}
	if rejectingStore.claimAttempts != 2 {
		t.Fatalf("claim attempts=%d; want 2", rejectingStore.claimAttempts)
	}
}

func TestMediaRetryV2DerivesIdentityAndRejectsDirectionMismatch(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	persistence := store.NewMemory()
	message := &waE2E.Message{ImageMessage: &waE2E.ImageMessage{MediaKey: bytesOf(32, 0x73)}}
	descriptor, _ := proto.Marshal(message)
	envelope, _ := json.Marshal(mediaRetryEnvelope{
		Message: descriptor, Chat: "5511999991234@s.whatsapp.net",
		Sender: "5511988887777@s.whatsapp.net", IsFromMe: true,
	})
	if err := persistence.PutMediaRetryState(t.Context(), domain.MediaRetryState{
		SessionID: "session-retry-v2", MessageID: "provider-retry-v2", Descriptor: envelope,
	}); err != nil {
		t.Fatalf("persist descriptor: %v", err)
	}
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client}).WithRecoveryStore(persistence)
	if err := adapter.RetryMedia(t.Context(), "session-retry-v2", domain.MediaRetryPayload{
		To: "+5511888887777", TargetMessageID: "provider-retry-v2", ExpectedDirection: "OUTBOUND",
	}); !errors.Is(err, ErrMediaRetryStateMissing) {
		t.Fatalf("chat mismatch did not fail closed: %v", err)
	}
	state, err := persistence.GetMediaRetryState(t.Context(), "session-retry-v2", "provider-retry-v2")
	if err != nil || state.Generation != 0 || state.Attempts != 0 {
		t.Fatalf("invalid chat consumed recovery generation: %+v err=%v", state, err)
	}
	if err := adapter.RetryMedia(t.Context(), "session-retry-v2", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-v2", ExpectedDirection: "SIDEWAYS",
	}); !errors.Is(err, ErrHistoryRecoveryInvalid) {
		t.Fatalf("unknown direction did not fail closed: %v", err)
	}
	state, err = persistence.GetMediaRetryState(t.Context(), "session-retry-v2", "provider-retry-v2")
	if err != nil || state.Generation != 0 || state.Attempts != 0 {
		t.Fatalf("unknown direction consumed recovery generation: %+v err=%v", state, err)
	}
	if err := adapter.RetryMedia(t.Context(), "session-retry-v2", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-v2", ExpectedDirection: "OUTBOUND",
	}); err != nil {
		t.Fatalf("v2 outbound retry: %v", err)
	}
	if client.retryInfo == nil || !client.retryInfo.IsFromMe || client.retryInfo.Sender.User != "5511988887777" {
		t.Fatalf("v2 did not derive descriptor identity: %+v", client.retryInfo)
	}
	if err := adapter.RetryMedia(t.Context(), "session-retry-v2", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-v2", ExpectedDirection: " outbound ",
	}); err != nil {
		t.Fatalf("normalized v2 outbound retry: %v", err)
	}
	if err := adapter.RetryMedia(t.Context(), "session-retry-v2", domain.MediaRetryPayload{
		To: "+5511999991234", TargetMessageID: "provider-retry-v2", ExpectedDirection: "INBOUND",
	}); !errors.Is(err, ErrMediaRetryStateMissing) {
		t.Fatalf("direction mismatch did not fail closed: %v", err)
	}
	state, err = persistence.GetMediaRetryState(t.Context(), "session-retry-v2", "provider-retry-v2")
	if err != nil || state.Generation != 2 || state.Attempts != 2 {
		t.Fatalf("invalid direction consumed recovery generation: %+v err=%v", state, err)
	}
}

func TestThumbnailAndStickerPackAreLimitedAndSanitized(t *testing.T) {
	t.Parallel()
	client := newFakeRecoveryClient()
	client.thumbnail = []byte("thumbnail")
	client.stickerPack = &types.StickerPack{
		StickerPackID: "pack_0001", Name: "Atendimento", Publisher: "Hub",
		Description: "Saudações", Animated: 1,
		Stickers: []*types.StickerPackItem{{
			MediaKey: bytesOf(32, 0x51), DirectPath: "/must-not-leak", URL: "https://must-not-leak.invalid",
			MimeType: "image/webp", FileSize: 1024, Width: 512, Height: 512,
			Emojis: []string{"👋"}, AccessibilityText: "aceno",
		}},
	}
	adapter := NewWhatsMeowAdapter(recoveryResolver{client: client})
	thumbnail, err := adapter.DownloadLinkThumbnail(t.Context(), "session-assets-0001", &waE2E.ExtendedTextMessage{
		ThumbnailDirectPath: proto.String("/thumbnail/opaque"),
		ThumbnailSHA256:     bytesOf(32, 0x61), ThumbnailEncSHA256: bytesOf(32, 0x62),
		MediaKey: bytesOf(32, 0x63),
	})
	if err != nil || string(thumbnail) != "thumbnail" {
		t.Fatalf("download thumbnail: data=%q err=%v", thumbnail, err)
	}
	summary, err := adapter.FetchStickerPackMetadata(t.Context(), "session-assets-0001", "pack_0001")
	if err != nil {
		t.Fatalf("fetch sticker pack metadata: %v", err)
	}
	if summary.ID != "pack_0001" || len(summary.Stickers) != 1 ||
		summary.Stickers[0].MIMEType != "image/webp" || summary.Stickers[0].AccessibilityText != "aceno" {
		t.Fatalf("unexpected sanitized sticker pack: %+v", summary)
	}
	encoded, err := json.Marshal(summary)
	if err != nil || strings.Contains(string(encoded), "must-not-leak") ||
		strings.Contains(string(encoded), "MediaKey") || strings.Contains(string(encoded), "DirectPath") {
		t.Fatalf("sticker metadata leaked transport secrets: %s err=%v", encoded, err)
	}
	client.thumbnail = make([]byte, maxThumbnailBytes+1)
	if _, err := adapter.DownloadLinkThumbnail(t.Context(), "session-assets-0001", &waE2E.ExtendedTextMessage{
		ThumbnailDirectPath: proto.String("/thumbnail/opaque"),
		ThumbnailSHA256:     bytesOf(32, 0x61), ThumbnailEncSHA256: bytesOf(32, 0x62),
		MediaKey: bytesOf(32, 0x63),
	}); !errors.Is(err, ErrRecoveryLimitExceeded) {
		t.Fatalf("oversized thumbnail must fail closed: %v", err)
	}
	if _, err := adapter.FetchStickerPackMetadata(t.Context(), "session-assets-0001", "https://invalid"); !errors.Is(err, ErrHistoryRecoveryInvalid) {
		t.Fatalf("arbitrary sticker pack URL must be rejected: %v", err)
	}
}

type recoveryResolver struct{ client WhatsMeowClient }

func (r recoveryResolver) Resolve(string) (WhatsMeowClient, error) { return r.client, nil }

func historyConversation(chat string, ids ...string) *waHistorySync.Conversation {
	messages := make([]*waHistorySync.HistorySyncMsg, 0, len(ids))
	for _, id := range ids {
		messages = append(messages, &waHistorySync.HistorySyncMsg{Message: &waWeb.WebMessageInfo{
			Key:              &waCommon.MessageKey{RemoteJID: proto.String(chat), ID: proto.String(id)},
			Message:          &waE2E.Message{Conversation: proto.String("history")},
			MessageTimestamp: proto.Uint64(1_700_000_000),
		}})
	}
	return &waHistorySync.Conversation{ID: proto.String(chat), Messages: messages}
}

func bytesOf(size int, value byte) []byte {
	data := make([]byte, size)
	for index := range data {
		data[index] = value
	}
	return data
}
