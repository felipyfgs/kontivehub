package protocol

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/spool"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"google.golang.org/protobuf/proto"
)

type fakeStickerDownloadClient struct {
	*fakeClient
	payload []byte
	err     error
	calls   int
}

type stickerDownloadResolver struct {
	client *fakeStickerDownloadClient
}

func (r stickerDownloadResolver) Resolve(string) (WhatsMeowClient, error) {
	return r.client, nil
}

func (c *fakeStickerDownloadClient) DownloadToFile(
	_ context.Context,
	_ whatsmeow.DownloadableMessage,
	file whatsmeow.File,
) error {
	c.calls++
	if c.err != nil {
		return c.err
	}
	_, err := file.Write(c.payload)
	return err
}

func TestMaterializeStickerDownloadsVerifiedWebP(t *testing.T) {
	t.Parallel()
	content := bytes.Repeat([]byte("webp-sticker-bytes-"), 32)
	digest := sha256.Sum256(content)
	digestHex := hex.EncodeToString(digest[:])
	client := &fakeStickerDownloadClient{
		fakeClient: &fakeClient{connected: true},
		payload:    content,
	}
	adapter, persistence, mediaSpool := newStickerMaterializationFixture(t, client)
	observationID := "observe-recent-0001"
	seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-01", observationID, digest[:], uint64(len(content)))

	result, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-01", domain.StickerMaterializationPayload{
		ObservationID: observationID, ExpectedSHA256: digestHex,
		ExpectedMIMEType: "image/webp", MaxBytes: domain.MaxStickerMaterializationBytes,
	})
	if err != nil {
		t.Fatalf("materialize verified sticker: %v", err)
	}
	if result.SHA256 != digestHex || result.SizeBytes != int64(len(content)) ||
		result.MIMEType != "image/webp" || result.SpoolID == "" {
		t.Fatalf("unexpected materialization result: %+v", result)
	}
	if client.calls != 1 {
		t.Fatalf("expected one download, got %d", client.calls)
	}
	if count, countErr := mediaSpool.Count(); countErr != nil || count != 1 {
		t.Fatalf("spool was not retained: count=%d err=%v", count, countErr)
	}
}

func TestMaterializeStickerRejectsIncompleteMetadata(t *testing.T) {
	t.Parallel()
	client := &fakeStickerDownloadClient{fakeClient: &fakeClient{connected: true}}
	adapter, persistence, _ := newStickerMaterializationFixture(t, client)
	observationID := "observe-incomplete-01"
	if err := persistence.PutStickerObservation(t.Context(), domain.StickerObservationState{
		SessionID: "session-sticker-mat-02", ObservationID: observationID,
		CorrelationAliases: []string{hex.EncodeToString(bytes.Repeat([]byte{0x11}, 32))},
		UpdatedAt:          time.Now().UTC(), ExpiresAt: time.Now().UTC().Add(time.Hour),
	}); err != nil {
		t.Fatalf("seed incomplete observation: %v", err)
	}

	_, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-02", domain.StickerMaterializationPayload{
		ObservationID: observationID, ExpectedSHA256: hex.EncodeToString(bytes.Repeat([]byte{0x22}, 32)),
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	assertStickerMaterializationReason(t, err, "INCOMPLETE_METADATA", false)
	if client.calls != 0 {
		t.Fatalf("incomplete metadata triggered download: calls=%d", client.calls)
	}
}

func TestMaterializeStickerRejectsExpiredObservationAndMedia(t *testing.T) {
	t.Parallel()
	content := []byte("expired-sticker-content")
	digest := sha256.Sum256(content)
	digestHex := hex.EncodeToString(digest[:])

	t.Run("expired observation", func(t *testing.T) {
		t.Parallel()
		client := &fakeStickerDownloadClient{fakeClient: &fakeClient{connected: true}, payload: content}
		adapter, persistence, _ := newStickerMaterializationFixture(t, client)
		observationID := "observe-expired-0001"
		seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-03", observationID, digest[:], uint64(len(content)))
		state, err := persistence.ResolveStickerObservation(
			t.Context(), "session-sticker-mat-03", observationID, time.Now().UTC(),
		)
		if err != nil {
			t.Fatalf("load observation: %v", err)
		}
		state.ExpiresAt = time.Now().UTC().Add(-time.Minute)
		if err := persistence.PutStickerObservation(t.Context(), state); err != nil {
			t.Fatalf("expire observation: %v", err)
		}
		_, err = adapter.MaterializeSticker(t.Context(), "session-sticker-mat-03", domain.StickerMaterializationPayload{
			ObservationID: observationID, ExpectedSHA256: digestHex,
			ExpectedMIMEType: "image/webp", MaxBytes: 1024,
		})
		assertStickerMaterializationReason(t, err, "MEDIA_EXPIRED", false)
	})

	t.Run("expired provider media", func(t *testing.T) {
		t.Parallel()
		client := &fakeStickerDownloadClient{
			fakeClient: &fakeClient{connected: true},
			err:        errors.New("cdn status 410 Gone: media expired"),
		}
		adapter, persistence, _ := newStickerMaterializationFixture(t, client)
		observationID := "observe-expired-0002"
		seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-04", observationID, digest[:], uint64(len(content)))
		_, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-04", domain.StickerMaterializationPayload{
			ObservationID: observationID, ExpectedSHA256: digestHex,
			ExpectedMIMEType: "image/webp", MaxBytes: 1024,
		})
		assertStickerMaterializationReason(t, err, "MEDIA_EXPIRED", false)
	})
}

func TestMaterializeStickerRejectsDigestMismatch(t *testing.T) {
	t.Parallel()
	expectedDigest := bytes.Repeat([]byte{0x33}, 32)
	content := []byte("downloaded-bytes-that-do-not-match-metadata-digest")
	client := &fakeStickerDownloadClient{
		fakeClient: &fakeClient{connected: true},
		payload:    content,
	}
	adapter, persistence, mediaSpool := newStickerMaterializationFixture(t, client)
	observationID := "observe-digest-0001"
	seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-05", observationID, expectedDigest, 64)

	_, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-05", domain.StickerMaterializationPayload{
		ObservationID: observationID, ExpectedSHA256: hex.EncodeToString(expectedDigest),
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	assertStickerMaterializationReason(t, err, "DIGEST_MISMATCH", false)
	if count, countErr := mediaSpool.Count(); countErr != nil || count != 0 {
		t.Fatalf("digest mismatch left spool residue: count=%d err=%v", count, countErr)
	}
}

func TestMaterializeStickerRejectsOversizedMedia(t *testing.T) {
	t.Parallel()
	content := bytes.Repeat([]byte("x"), 128)
	digest := sha256.Sum256(content)
	client := &fakeStickerDownloadClient{
		fakeClient: &fakeClient{connected: true},
		payload:    content,
	}
	adapter, persistence, _ := newStickerMaterializationFixture(t, client)
	observationID := "observe-oversize-001"
	seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-06", observationID, digest[:], uint64(len(content)))

	_, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-06", domain.StickerMaterializationPayload{
		ObservationID: observationID, ExpectedSHA256: hex.EncodeToString(digest[:]),
		ExpectedMIMEType: "image/webp", MaxBytes: 64,
	})
	assertStickerMaterializationReason(t, err, "MEDIA_TOO_LARGE", false)
	if client.calls != 0 {
		t.Fatalf("oversized metadata still downloaded: calls=%d", client.calls)
	}
}

func TestMaterializeStickerCorrelatesFavoriteWithRecentDescriptor(t *testing.T) {
	t.Parallel()
	content := []byte("correlated-favorite-sticker-webp")
	digest := sha256.Sum256(content)
	digestHex := hex.EncodeToString(digest[:])
	imageHash := "device-image-hash-shared"
	aliases := stickerCorrelationAliases(imageHash, bytes.Repeat([]byte{0x44}, 32), digest[:])
	client := &fakeStickerDownloadClient{
		fakeClient: &fakeClient{connected: true},
		payload:    content,
	}
	adapter, persistence, _ := newStickerMaterializationFixture(t, client)

	recentID := "observe-recent-corr-01"
	favoriteID := "observe-favorite-corr1"
	seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-07", recentID, digest[:], uint64(len(content)))
	recent, err := persistence.ResolveStickerObservation(
		t.Context(), "session-sticker-mat-07", recentID, time.Now().UTC(),
	)
	if err != nil {
		t.Fatalf("load recent observation: %v", err)
	}
	recent.CorrelationAliases = aliases
	if err := persistence.PutStickerObservation(t.Context(), recent); err != nil {
		t.Fatalf("update recent aliases: %v", err)
	}
	if err := persistence.PutStickerObservation(t.Context(), domain.StickerObservationState{
		SessionID: "session-sticker-mat-07", ObservationID: favoriteID,
		CorrelationAliases: aliases,
		UpdatedAt:          time.Now().UTC(), ExpiresAt: time.Now().UTC().Add(time.Hour),
	}); err != nil {
		t.Fatalf("seed favorite observation: %v", err)
	}

	result, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-07", domain.StickerMaterializationPayload{
		ObservationID: favoriteID, ExpectedSHA256: digestHex,
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	if err != nil {
		t.Fatalf("correlate favorite materialization: %v", err)
	}
	if result.SHA256 != digestHex || client.calls != 1 {
		t.Fatalf("favorite correlation failed: result=%+v calls=%d", result, client.calls)
	}
}

func TestMaterializeStickerDoesNotRetainDurableCatalog(t *testing.T) {
	t.Parallel()
	content := []byte("ephemeral-sticker-payload")
	digest := sha256.Sum256(content)
	client := &fakeStickerDownloadClient{
		fakeClient: &fakeClient{connected: true},
		payload:    content,
	}
	adapter, persistence, mediaSpool := newStickerMaterializationFixture(t, client)
	observationID := "observe-ephemeral-001"
	seedDownloadableRecentObservation(t, persistence, "session-sticker-mat-08", observationID, digest[:], uint64(len(content)))
	result, err := adapter.MaterializeSticker(t.Context(), "session-sticker-mat-08", domain.StickerMaterializationPayload{
		ObservationID: observationID, ExpectedSHA256: hex.EncodeToString(digest[:]),
		ExpectedMIMEType: "image/webp", MaxBytes: 1024,
	})
	if err != nil {
		t.Fatalf("materialize: %v", err)
	}
	if err := mediaSpool.Ack(result.SpoolID); err != nil {
		t.Fatalf("ack spool: %v", err)
	}
	if count, countErr := mediaSpool.Count(); countErr != nil || count != 0 {
		t.Fatalf("spool retained after ack: count=%d err=%v", count, countErr)
	}
	events, err := persistence.NextEvents(t.Context(), 10, time.Now().Add(time.Second))
	if err != nil && !errors.Is(err, domain.ErrNotFound) {
		t.Fatalf("read events: %v", err)
	}
	if len(events) != 0 {
		t.Fatalf("materialization wrote durable catalog events from adapter: %d", len(events))
	}
}

func newStickerMaterializationFixture(
	t *testing.T,
	client *fakeStickerDownloadClient,
) (*WhatsMeowAdapter, *store.Memory, *spool.Store) {
	t.Helper()
	persistence := store.NewMemory()
	box, err := cryptobox.New(bytes.Repeat([]byte{0x5a}, 32))
	if err != nil {
		t.Fatalf("create spool box: %v", err)
	}
	mediaSpool, err := spool.Open(t.TempDir(), box)
	if err != nil {
		t.Fatalf("open spool: %v", err)
	}
	adapter := NewWhatsMeowAdapter(stickerDownloadResolver{client: client}).
		WithStickerMaterialization(persistence, mediaSpool, domain.MaxStickerMaterializationBytes)
	return adapter, persistence, mediaSpool
}

func seedDownloadableRecentObservation(
	t *testing.T,
	persistence store.Store,
	sessionID string,
	observationID string,
	fileSHA256 []byte,
	fileLength uint64,
) {
	t.Helper()
	metadata := &waHistorySync.StickerMetadata{
		FileSHA256: fileSHA256, FileEncSHA256: bytes.Repeat([]byte{0x55}, 32),
		MediaKey: bytes.Repeat([]byte{0x56}, 32), DirectPath: proto.String("/private/sticker-path"),
		Mimetype: proto.String("image/webp"), FileLength: proto.Uint64(fileLength),
		Width: proto.Uint32(512), Height: proto.Uint32(512),
	}
	encoded, err := proto.Marshal(metadata)
	if err != nil {
		t.Fatalf("marshal sticker metadata: %v", err)
	}
	descriptor, err := json.Marshal(stickerDescriptorEnvelope{Kind: "RECENT", Data: encoded})
	if err != nil {
		t.Fatalf("encode descriptor: %v", err)
	}
	aliases := stickerCorrelationAliases("seed-image-hash", metadata.GetFileEncSHA256(), fileSHA256)
	if err := persistence.PutStickerObservation(t.Context(), domain.StickerObservationState{
		SessionID: sessionID, ObservationID: observationID, Descriptor: descriptor,
		CorrelationAliases: aliases,
		UpdatedAt:          time.Now().UTC(), ExpiresAt: time.Now().UTC().Add(time.Hour),
	}); err != nil {
		t.Fatalf("seed observation: %v", err)
	}
}

func assertStickerMaterializationReason(t *testing.T, err error, reason string, retryable bool) {
	t.Helper()
	var materializationError *StickerMaterializationError
	if !errors.As(err, &materializationError) {
		t.Fatalf("expected StickerMaterializationError, got %v", err)
	}
	if materializationError.Reason != reason || materializationError.Retryable != retryable {
		t.Fatalf("unexpected failure: reason=%q retryable=%t want %q/%t",
			materializationError.Reason, materializationError.Retryable, reason, retryable)
	}
}
