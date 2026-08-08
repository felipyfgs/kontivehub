package protocol

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/spool"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/proto/waHistorySync"
	"golang.org/x/sys/unix"
	"google.golang.org/protobuf/proto"
)

const stickerObservationRetention = 30 * time.Minute

var ErrStickerMediaExpired = errors.New("sticker media expired")

type stickerDescriptorEnvelope struct {
	Kind string `json:"kind"`
	Data []byte `json:"data"`
}

type StickerMaterializationResult struct {
	SpoolID   string
	SizeBytes int64
	SHA256    string
	MIMEType  string
}

type StickerMaterializationError struct {
	Reason    string
	Retryable bool
	Err       error
}

func (e *StickerMaterializationError) Error() string {
	return "sticker materialization failed: " + e.Reason
}

func (e *StickerMaterializationError) Unwrap() error { return e.Err }

type stickerDownloadClient interface {
	DownloadToFile(context.Context, whatsmeow.DownloadableMessage, whatsmeow.File) error
}

func (b *EventBridge) rememberStickerObservation(
	ctx context.Context,
	sessionID string,
	observationID string,
	availability string,
	message proto.Message,
	aliases []string,
) error {
	if len(aliases) == 0 {
		return nil
	}
	state := domain.StickerObservationState{
		SessionID: sessionID, ObservationID: observationID,
		CorrelationAliases: aliases,
		UpdatedAt:          time.Now().UTC(), ExpiresAt: time.Now().UTC().Add(stickerObservationRetention),
	}
	if availability == "AVAILABLE" && message != nil {
		var kind string
		switch message.(type) {
		case *waHistorySync.StickerMetadata:
			kind = "RECENT"
		case *waE2E.StickerMessage:
			kind = "MESSAGE"
		default:
			return errors.New("unsupported sticker descriptor")
		}
		encoded, err := proto.Marshal(message)
		if err != nil {
			return err
		}
		state.Descriptor, err = json.Marshal(stickerDescriptorEnvelope{Kind: kind, Data: encoded})
		if err != nil {
			return err
		}
	}
	return b.store.PutStickerObservation(ctx, state)
}

func stickerCorrelationAliases(imageHash string, encryptedDigest, plainDigest []byte) []string {
	values := make([]string, 0, 3)
	appendAlias := func(kind, value string) {
		if value == "" || len(value) > 4096 {
			return
		}
		digest := sha256.Sum256([]byte(kind + "\x00" + value))
		alias := hex.EncodeToString(digest[:])
		for _, existing := range values {
			if existing == alias {
				return
			}
		}
		values = append(values, alias)
	}
	appendAlias("image", imageHash)
	if len(encryptedDigest) == sha256.Size {
		appendAlias("encrypted", hex.EncodeToString(encryptedDigest))
	}
	if len(plainDigest) == sha256.Size {
		appendAlias("plain", hex.EncodeToString(plainDigest))
	}
	return values
}

func (a *WhatsMeowAdapter) MaterializeSticker(
	ctx context.Context,
	sessionID string,
	payload domain.StickerMaterializationPayload,
) (StickerMaterializationResult, error) {
	if a.stickerStore == nil || a.stickerSpool == nil {
		return StickerMaterializationResult{}, stickerMaterializationFailure(
			"MATERIALIZATION_UNAVAILABLE", true, nil,
		)
	}
	state, err := a.stickerStore.ResolveStickerObservation(ctx, sessionID, payload.ObservationID, time.Now().UTC())
	if errors.Is(err, domain.ErrStickerObservationExpired) {
		return StickerMaterializationResult{}, stickerMaterializationFailure("MEDIA_EXPIRED", false, err)
	}
	if errors.Is(err, domain.ErrNotFound) {
		return StickerMaterializationResult{}, stickerMaterializationFailure("INCOMPLETE_METADATA", false, err)
	}
	if err != nil {
		return StickerMaterializationResult{}, stickerMaterializationFailure("METADATA_UNAVAILABLE", true, err)
	}
	media, err := decodeStickerDescriptor(state.Descriptor)
	if err != nil {
		return StickerMaterializationResult{}, stickerMaterializationFailure("INCOMPLETE_METADATA", false, err)
	}
	limit := min(payload.MaxBytes, domain.MaxStickerMaterializationBytes)
	if a.stickerMaxBytes > 0 {
		limit = min(limit, a.stickerMaxBytes)
	}
	if reason := validateStickerDownloadMetadata(media, payload, limit); reason != "" {
		return StickerMaterializationResult{}, stickerMaterializationFailure(reason, false, nil)
	}
	client, err := a.readyRecoveryClient(sessionID)
	if err != nil {
		return StickerMaterializationResult{}, stickerMaterializationFailure("SESSION_UNAVAILABLE", true, err)
	}
	downloader, ok := client.(stickerDownloadClient)
	if !ok {
		return StickerMaterializationResult{}, stickerMaterializationFailure("UNSUPPORTED", false, nil)
	}
	spoolID := stableID("sticker-materialized", sessionID, payload.ObservationID, payload.ExpectedSHA256)
	record, err := downloadStickerToSpool(ctx, downloader, a.stickerSpool, spoolID, media, limit)
	if err != nil {
		if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
			return StickerMaterializationResult{}, stickerMaterializationFailure("DOWNLOAD_FAILED", true, err)
		}
		if errors.Is(err, ErrStickerMediaExpired) || stickerDownloadExpired(err) {
			return StickerMaterializationResult{}, stickerMaterializationFailure("MEDIA_EXPIRED", false, err)
		}
		if errors.Is(err, errStickerMaterializationTooLarge) {
			return StickerMaterializationResult{}, stickerMaterializationFailure("MEDIA_TOO_LARGE", false, err)
		}
		return StickerMaterializationResult{}, stickerMaterializationFailure("DOWNLOAD_FAILED", true, err)
	}
	if record.SHA256 != payload.ExpectedSHA256 {
		_ = a.stickerSpool.Ack(record.ID)
		return StickerMaterializationResult{}, stickerMaterializationFailure("DIGEST_MISMATCH", false, nil)
	}
	return StickerMaterializationResult{
		SpoolID: record.ID, SizeBytes: record.SizeBytes, SHA256: record.SHA256, MIMEType: "image/webp",
	}, nil
}

func decodeStickerDescriptor(descriptor []byte) (whatsmeow.DownloadableMessage, error) {
	var envelope stickerDescriptorEnvelope
	if len(descriptor) == 0 || json.Unmarshal(descriptor, &envelope) != nil || len(envelope.Data) == 0 {
		return nil, errors.New("invalid sticker descriptor")
	}
	switch envelope.Kind {
	case "RECENT":
		message := &waHistorySync.StickerMetadata{}
		if err := proto.Unmarshal(envelope.Data, message); err != nil {
			return nil, err
		}
		return message, nil
	case "MESSAGE":
		message := &waE2E.StickerMessage{}
		if err := proto.Unmarshal(envelope.Data, message); err != nil {
			return nil, err
		}
		return message, nil
	default:
		return nil, errors.New("unsupported sticker descriptor")
	}
}

func validateStickerDownloadMetadata(
	media whatsmeow.DownloadableMessage,
	payload domain.StickerMaterializationPayload,
	limit int64,
) string {
	mime, ok := media.(interface{ GetMimetype() string })
	length, lengthOK := media.(interface{ GetFileLength() uint64 })
	directPath, pathOK := media.(interface{ GetDirectPath() string })
	mediaKey, keyOK := media.(interface{ GetMediaKey() []byte })
	plainDigest, digestOK := media.(interface{ GetFileSHA256() []byte })
	encryptedDigest, encryptedOK := media.(interface{ GetFileEncSHA256() []byte })
	if !ok || mime.GetMimetype() != payload.ExpectedMIMEType || mime.GetMimetype() != "image/webp" {
		return "UNSUPPORTED"
	}
	if !lengthOK || length.GetFileLength() == 0 || length.GetFileLength() > uint64(limit) {
		return "MEDIA_TOO_LARGE"
	}
	if !pathOK || strings.TrimSpace(directPath.GetDirectPath()) == "" ||
		!keyOK || len(mediaKey.GetMediaKey()) == 0 ||
		!digestOK || len(plainDigest.GetFileSHA256()) != sha256.Size ||
		!encryptedOK || len(encryptedDigest.GetFileEncSHA256()) != sha256.Size {
		return "INCOMPLETE_METADATA"
	}
	if hex.EncodeToString(plainDigest.GetFileSHA256()) != payload.ExpectedSHA256 {
		return "DIGEST_MISMATCH"
	}
	return ""
}

var errStickerMaterializationTooLarge = errors.New("sticker materialization exceeds limit")

func downloadStickerToSpool(
	ctx context.Context,
	client stickerDownloadClient,
	mediaSpool *spool.Store,
	spoolID string,
	media whatsmeow.DownloadableMessage,
	limit int64,
) (spool.Record, error) {
	fd, err := unix.MemfdCreate("whatsapp-sticker", unix.MFD_CLOEXEC)
	if err != nil {
		return spool.Record{}, err
	}
	file := os.NewFile(uintptr(fd), "whatsapp-sticker")
	defer file.Close()
	if err := client.DownloadToFile(ctx, media, file); err != nil {
		return spool.Record{}, err
	}
	info, err := file.Stat()
	if err != nil {
		return spool.Record{}, err
	}
	if info.Size() < 1 || info.Size() > limit {
		return spool.Record{}, errStickerMaterializationTooLarge
	}
	if _, err := file.Seek(0, 0); err != nil {
		return spool.Record{}, err
	}
	return mediaSpool.Put(ctx, spoolID, file)
}

func stickerMaterializationFailure(reason string, retryable bool, err error) error {
	return &StickerMaterializationError{Reason: reason, Retryable: retryable, Err: err}
}

func stickerDownloadExpired(err error) bool {
	message := strings.ToLower(err.Error())
	return strings.Contains(message, "expired") || strings.Contains(message, "status 404") ||
		strings.Contains(message, "status 410") || strings.Contains(message, "not found")
}

func (r StickerMaterializationResult) String() string {
	return fmt.Sprintf("sticker materialized (%d bytes)", r.SizeBytes)
}
