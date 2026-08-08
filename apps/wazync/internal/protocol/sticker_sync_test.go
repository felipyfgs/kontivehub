package protocol

import (
	"bytes"
	"encoding/json"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waSyncAction"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

func TestRecentStickerObservationsAreBoundedDeduplicatedAndSanitized(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	digest := bytes.Repeat([]byte{0x31}, 32)
	metadata := &waHistorySync.StickerMetadata{
		FileSHA256: digest, FileEncSHA256: bytes.Repeat([]byte{0x32}, 32),
		MediaKey: []byte("media-key-secret"), DirectPath: proto.String("/secret/direct-path"),
		Mimetype: proto.String("image/webp"), FileLength: proto.Uint64(512),
		Width: proto.Uint32(512), Height: proto.Uint32(512), LastStickerSentTS: proto.Int64(1_700_000_000),
	}
	historyType := waHistorySync.HistorySync_RECENT
	bridge.handle(t.Context(), "session-stickers-0001", nil, &events.HistorySync{Data: &waHistorySync.HistorySync{
		SyncType: &historyType, RecentStickers: []*waHistorySync.StickerMetadata{metadata, metadata},
	}})

	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventStickerObserved)
	if payload["availability"] != "AVAILABLE" || payload["source"] != "DEVICE_RECENT" {
		t.Fatalf("unexpected recent sticker payload: %#v", payload)
	}
	encoded, _ := json.Marshal(payload)
	for _, secret := range []string{"media-key-secret", "/secret/direct-path", "FileEncSHA256"} {
		if bytes.Contains(encoded, []byte(secret)) {
			t.Fatalf("sticker observation leaked transport secret %q: %s", secret, encoded)
		}
	}
	count := 0
	for _, item := range pending {
		if item.Event.Type == domain.EventStickerObserved {
			count++
		}
	}
	if count != 1 {
		t.Fatalf("duplicate recent stickers produced %d observations", count)
	}
}

func TestFavoriteStickerAppStateIsAllowlistedAndUnknownStateIsDropped(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	now := time.Now().UTC().Truncate(time.Millisecond)
	bridge.handle(t.Context(), "session-stickers-0002", nil, &events.AppState{
		Index: []string{"favoriteSticker", "opaque-device-index"},
		SyncActionValue: &waSyncAction.SyncActionValue{
			Timestamp: proto.Int64(now.UnixMilli()),
			StickerAction: &waSyncAction.StickerAction{
				Mimetype: proto.String("image/webp"), FileLength: proto.Uint64(700),
				Width: proto.Uint32(512), Height: proto.Uint32(512), IsFavorite: proto.Bool(true),
				ImageHash: proto.String("device-image-hash"), DirectPath: proto.String("/favorite-secret"),
				MediaKey: []byte("favorite-media-key-secret"),
			},
		},
	})
	bridge.handle(t.Context(), "session-stickers-0002", nil, &events.AppState{
		Index:           []string{"unknownAccountState", "raw-secret-index"},
		SyncActionValue: &waSyncAction.SyncActionValue{Timestamp: proto.Int64(now.UnixMilli())},
	})

	pending := pendingEvents(t, persistence)
	payload := payloadForType(t, pending, domain.EventStickerFavoriteChanged)
	if payload["favorite"] != true || payload["source"] != "DEVICE_FAVORITE" {
		t.Fatalf("unexpected favorite payload: %#v", payload)
	}
	encoded, _ := json.Marshal(pending)
	for _, secret := range []string{"/favorite-secret", "favorite-media-key-secret", "raw-secret-index"} {
		if bytes.Contains(encoded, []byte(secret)) {
			t.Fatalf("favorite event leaked raw app-state secret %q", secret)
		}
	}
}

func TestStickerObservationClassifiesIncompleteAndUnsupportedMetadata(t *testing.T) {
	t.Parallel()
	if got := syncedStickerAvailability("image/webp", 100, 512, 512, "", nil, nil, nil); got != "INCOMPLETE_METADATA" {
		t.Fatalf("expected incomplete metadata, got %q", got)
	}
	if got := syncedStickerAvailability("image/png", 100, 512, 512, "/x", []byte{1}, bytes.Repeat([]byte{1}, 32), bytes.Repeat([]byte{2}, 32)); got != "UNSUPPORTED" {
		t.Fatalf("expected unsupported metadata, got %q", got)
	}
	if got := syncedStickerAvailability("image/webp", maxSyncedStickerBytes+1, 512, 512, "/x", []byte{1}, bytes.Repeat([]byte{1}, 32), bytes.Repeat([]byte{2}, 32)); got != "UNSUPPORTED" {
		t.Fatalf("expected oversized metadata to be unsupported, got %q", got)
	}
}

func TestFavoriteStickerAcceptsStateOnlyMutation(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	bridge.handle(t.Context(), "session-stickers-0003", nil, &events.AppState{
		Index: []string{"favoriteSticker"},
		SyncActionValue: &waSyncAction.SyncActionValue{
			Timestamp: proto.Int64(time.Now().UnixMilli()),
			StickerAction: &waSyncAction.StickerAction{
				ImageHash: proto.String("state-only-image-hash"), IsFavorite: proto.Bool(true),
			},
		},
	})

	payload := payloadForType(t, pendingEvents(t, persistence), domain.EventStickerFavoriteChanged)
	if payload["favorite"] != true || payload["mime_type"] != "" || payload["size_bytes"] != float64(0) {
		t.Fatalf("state-only favorite was not preserved safely: %#v", payload)
	}
}

func TestLiveStickerMessageProducesLibraryObservation(t *testing.T) {
	t.Parallel()
	persistence := store.NewMemory()
	bridge := NewEventBridge(persistence, nil, 20<<20)
	digest := bytes.Repeat([]byte{0x41}, 32)
	bridge.handleMessageStickerObservation(t.Context(), "session-stickers-0004", &events.Message{
		Info: types.MessageInfo{ID: types.MessageID("provider-sticker-0001"), Timestamp: time.Now()},
	}, &waE2E.StickerMessage{
		FileSHA256: digest, FileEncSHA256: bytes.Repeat([]byte{0x42}, 32), MediaKey: []byte{1},
		DirectPath: proto.String("/private"), Mimetype: proto.String("image/webp"),
		FileLength: proto.Uint64(500), Width: proto.Uint32(512), Height: proto.Uint32(512),
	})

	payload := payloadForType(t, pendingEvents(t, persistence), domain.EventStickerObserved)
	if payload["source"] != "DEVICE_MESSAGE" || payload["availability"] != "AVAILABLE" {
		t.Fatalf("unexpected live sticker observation: %#v", payload)
	}
}
