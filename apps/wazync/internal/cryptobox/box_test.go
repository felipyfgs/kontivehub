package cryptobox

import (
	"bytes"
	"testing"
)

func TestDigestIsStableAndKeyScoped(t *testing.T) {
	t.Parallel()
	first, err := New(bytes.Repeat([]byte("a"), 32))
	if err != nil {
		t.Fatalf("new box: %v", err)
	}
	second, err := New(bytes.Repeat([]byte("b"), 32))
	if err != nil {
		t.Fatalf("new box: %v", err)
	}
	plain := []byte("5511999991234:12@s.whatsapp.net")
	one := first.Digest(plain)
	again := first.Digest(plain)
	other := second.Digest(plain)
	if !bytes.Equal(one, again) || len(one) != 32 {
		t.Fatalf("digest must be stable sha256-hmac: len=%d equal=%v", len(one), bytes.Equal(one, again))
	}
	if bytes.Equal(one, other) {
		t.Fatal("digest must change with the data key")
	}
}
