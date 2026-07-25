package protocol

import (
	"context"
	"errors"
	"testing"

	"go.mau.fi/whatsmeow/types"
)

type fakePNResolver struct {
	mapped types.JID
	err    error
	calls  int
}

func (r *fakePNResolver) GetPNForLID(context.Context, types.JID) (types.JID, error) {
	r.calls++
	return r.mapped, r.err
}

func TestNormalizeOneToOneAddressAllowsOnlyPNAndLID(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name       string
		input      string
		kind       AddressKind
		normalized string
	}{
		{name: "e164", input: "+5511999991234", kind: AddressPN, normalized: "+5511999991234"},
		{name: "pn jid", input: "5511999991234@s.whatsapp.net", kind: AddressPN, normalized: "+5511999991234"},
		{name: "opaque lid", input: "lid:123456789012345", kind: AddressLID, normalized: "lid:123456789012345"},
		{name: "lid jid", input: "123456789012345@lid", kind: AddressLID, normalized: "lid:123456789012345"},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			address, err := NormalizeOneToOneAddress(test.input)
			if err != nil {
				t.Fatalf("normalize: %v", err)
			}
			if address.Kind != test.kind || address.Normalized != test.normalized {
				t.Fatalf("unexpected address: %+v", address)
			}
		})
	}
}

func TestNormalizeOneToOneAddressRejectsEveryNonIndividualServer(t *testing.T) {
	t.Parallel()
	tests := []string{
		"120363000000000000@g.us",        // group and community
		"120363000000000000@newsletter",  // newsletter and channel
		"123456789@broadcast",            // broadcast list
		"status@broadcast",               // status
		"123456789@hosted",               // known but out-of-scope server
		"123456789@unknown.example",      // unknown server
		"123456789:2@s.whatsapp.net",     // device-targeted user JID
		"123456789:65536@s.whatsapp.net", // overflowing device ID must not become device zero
		"867051314767696@bot",            // bot
	}
	for _, input := range tests {
		input := input
		t.Run(input, func(t *testing.T) {
			t.Parallel()
			_, err := NormalizeOneToOneAddress(input)
			if !errors.Is(err, ErrRecipientScopeNotAllowed) {
				t.Fatalf("expected scope rejection, got %v", err)
			}
		})
	}
}

func TestResolvePNUsesOnlyTheProvidedSessionResolverAndFailsClosed(t *testing.T) {
	t.Parallel()
	lid, err := NormalizeOneToOneAddress("lid:123456789012345")
	if err != nil {
		t.Fatalf("normalize LID: %v", err)
	}
	resolver := &fakePNResolver{mapped: types.NewJID("5511999991234", types.DefaultUserServer)}
	pn, err := lid.ResolvePN(t.Context(), resolver)
	if err != nil {
		t.Fatalf("resolve PN: %v", err)
	}
	if resolver.calls != 1 || pn.Kind != AddressPN || pn.Normalized != "+5511999991234" {
		t.Fatalf("unexpected resolution: calls=%d address=%+v", resolver.calls, pn)
	}

	if _, err := lid.ResolvePN(t.Context(), nil); !errors.Is(err, ErrRecipientPNMappingMissing) {
		t.Fatalf("nil resolver must fail closed: %v", err)
	}
	resolver = &fakePNResolver{mapped: types.NewJID("120363000000000000", types.GroupServer)}
	if _, err := lid.ResolvePN(t.Context(), resolver); !errors.Is(err, ErrRecipientScopeNotAllowed) {
		t.Fatalf("out-of-scope mapping must fail closed: %v", err)
	}
}

func TestContractAddressRejectsRawUserJIDWhileInternalNormalizerKeepsCompatibility(t *testing.T) {
	t.Parallel()
	raw := "5511999991234@s.whatsapp.net"
	if _, err := NormalizeOneToOneAddress(raw); err != nil {
		t.Fatalf("trusted internal normalizer must parse upstream user JID: %v", err)
	}
	if _, err := NormalizeContractOneToOneAddress(raw); !errors.Is(err, ErrRecipientInvalid) {
		t.Fatalf("contract accepted raw user JID: %v", err)
	}
	if normalized, err := NormalizeContractOneToOneAddress("+5511999991234"); err != nil || normalized.Normalized != "+5511999991234" {
		t.Fatalf("contract rejected normalized E.164: %+v err=%v", normalized, err)
	}
}
