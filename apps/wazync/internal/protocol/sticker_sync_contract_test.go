package protocol

import (
	"reflect"
	"testing"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/appstate"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"go.mau.fi/whatsmeow/proto/waSyncAction"
)

func TestPinnedWhatsmeowStickerSyncContract(t *testing.T) {
	t.Parallel()

	history := reflect.TypeOf(waHistorySync.HistorySync{})
	if field, ok := history.FieldByName("RecentStickers"); !ok || field.Type != reflect.TypeOf([]*waHistorySync.StickerMetadata{}) {
		t.Fatalf("pinned HistorySync.RecentStickers contract drifted: %#v %v", field, ok)
	}
	downloadable := reflect.TypeOf((*whatsmeow.DownloadableMessage)(nil)).Elem()
	if !reflect.TypeOf((*waHistorySync.StickerMetadata)(nil)).Implements(downloadable) {
		t.Fatal("pinned StickerMetadata is no longer downloadable")
	}
	if appstate.IndexFavoriteSticker != "favoriteSticker" {
		t.Fatalf("favorite sticker app-state index drifted: %q", appstate.IndexFavoriteSticker)
	}
	action := reflect.TypeOf(waSyncAction.StickerAction{})
	for _, name := range []string{
		"FileEncSHA256", "MediaKey", "Mimetype", "Height", "Width", "DirectPath",
		"FileLength", "IsFavorite", "IsLottie", "ImageHash",
	} {
		if _, ok := action.FieldByName(name); !ok {
			t.Fatalf("pinned StickerAction.%s contract drifted", name)
		}
	}
	if method, ok := reflect.TypeOf((*whatsmeow.Client)(nil)).MethodByName("FetchStickerPack"); !ok || method.Type.NumIn() != 3 {
		t.Fatalf("pinned Client.FetchStickerPack contract drifted: %#v %v", method, ok)
	}
}
