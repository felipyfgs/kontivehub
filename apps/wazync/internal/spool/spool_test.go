package spool

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"testing"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
)

func TestEncryptedSpoolSurvivesRestartAndIsRemovedOnlyAfterAck(t *testing.T) {
	t.Parallel()
	directory := t.TempDir()
	key := bytes.Repeat([]byte("k"), 32)
	box, _ := cryptobox.New(key)
	spool, err := Open(directory, box)
	if err != nil {
		t.Fatalf("open spool: %v", err)
	}
	payload := bytes.Repeat([]byte("private-media-chunk-"), 15_000)
	record, err := spool.Put(context.Background(), "media-event-0001", bytes.NewReader(payload))
	if err != nil {
		t.Fatalf("put spool: %v", err)
	}
	digest := sha256.Sum256(payload)
	if record.SizeBytes != int64(len(payload)) || record.SHA256 != hex.EncodeToString(digest[:]) {
		t.Fatalf("unexpected record: %+v", record)
	}
	raw, err := os.ReadFile(spool.path(record.ID))
	if err != nil || bytes.Contains(raw, []byte("private-media-chunk")) {
		t.Fatal("spool persisted plaintext media")
	}
	if !bytes.HasPrefix(raw, []byte("WZSP1")) {
		t.Fatalf("spool does not use the Wazync format marker: %q", raw[:min(len(raw), len(magic))])
	}

	restarted, err := Open(directory, box)
	if err != nil {
		t.Fatalf("restart spool: %v", err)
	}
	reader, err := restarted.Reader(context.Background(), record.ID)
	if err != nil {
		t.Fatalf("open reader: %v", err)
	}
	recovered, err := io.ReadAll(reader)
	_ = reader.Close()
	if err != nil || !bytes.Equal(payload, recovered) {
		t.Fatalf("recover media: bytes=%d err=%v", len(recovered), err)
	}
	if count, _ := restarted.Count(); count != 1 {
		t.Fatalf("spool disappeared before ACK: %d", count)
	}
	if err := restarted.Ack(record.ID); err != nil {
		t.Fatalf("ack spool: %v", err)
	}
	if count, _ := restarted.Count(); count != 0 {
		t.Fatalf("spool remains after ACK: %d", count)
	}
}

func TestEncryptedSpoolRejectsWrongKey(t *testing.T) {
	t.Parallel()
	directory := t.TempDir()
	firstBox, _ := cryptobox.New(bytes.Repeat([]byte("a"), 32))
	first, _ := Open(directory, firstBox)
	_, err := first.Put(context.Background(), "media-event-0002", bytes.NewReader([]byte("secret")))
	if err != nil {
		t.Fatalf("put spool: %v", err)
	}

	wrongBox, _ := cryptobox.New(bytes.Repeat([]byte("b"), 32))
	wrong, _ := Open(directory, wrongBox)
	reader, err := wrong.Reader(context.Background(), "media-event-0002")
	if err != nil {
		t.Fatalf("open encrypted file: %v", err)
	}
	_, err = io.ReadAll(reader)
	_ = reader.Close()
	if err == nil {
		t.Fatal("wrong key decrypted spool")
	}
}
