package protocol

import (
	"context"
	"errors"
	"regexp"
	"strings"

	"go.mau.fi/whatsmeow/types"
)

var (
	ErrRecipientInvalid          = errors.New("RECIPIENT_INVALID")
	ErrRecipientScopeNotAllowed  = errors.New("RECIPIENT_SCOPE_NOT_ALLOWED")
	ErrRecipientPNMappingMissing = errors.New("RECIPIENT_PN_MAPPING_NOT_FOUND")
)

var (
	e164Pattern = regexp.MustCompile(`^[1-9][0-9]{7,14}$`)
	lidPattern  = regexp.MustCompile(`^[1-9][0-9]{0,19}$`)
)

type AddressKind string

const (
	AddressPN  AddressKind = "PN"
	AddressLID AddressKind = "LID"
)

// OneToOneAddress is the only JID representation accepted beyond the gateway
// boundary. Normalized is safe for the typed internal contract: PN uses E.164
// and LID uses an opaque lid: prefix rather than exposing a raw JID.
type OneToOneAddress struct {
	JID        types.JID
	Kind       AddressKind
	Normalized string
}

// PNResolver is intentionally session-scoped by its caller. In production it
// is backed by the LID store attached to the current session's device.
type PNResolver interface {
	GetPNForLID(context.Context, types.JID) (types.JID, error)
}

// NormalizeOneToOneAddress accepts normalized E.164, opaque LID identities and
// the two explicitly allowlisted whatsmeow user servers. It never guesses a
// server for values that resemble a raw JID.
func NormalizeOneToOneAddress(raw string) (OneToOneAddress, error) {
	if raw == "" || raw != strings.TrimSpace(raw) {
		return OneToOneAddress{}, ErrRecipientInvalid
	}

	switch {
	case strings.HasPrefix(raw, "+"):
		return normalizeUserJID(types.NewJID(strings.TrimPrefix(raw, "+"), types.DefaultUserServer))
	case strings.HasPrefix(raw, "lid:"):
		return normalizeUserJID(types.NewJID(strings.TrimPrefix(raw, "lid:"), types.HiddenUserServer))
	case strings.Count(raw, "@") == 1:
		local, _, _ := strings.Cut(raw, "@")
		if strings.ContainsAny(local, ".:") {
			return OneToOneAddress{}, ErrRecipientScopeNotAllowed
		}
		jid, err := types.ParseJID(raw)
		if err != nil {
			return OneToOneAddress{}, ErrRecipientInvalid
		}
		return normalizeUserJID(jid)
	case strings.Contains(raw, "@"):
		return OneToOneAddress{}, ErrRecipientInvalid
	default:
		return OneToOneAddress{}, ErrRecipientInvalid
	}
}

// NormalizeContractOneToOneAddress enforces the transport DTO representation.
// Raw user JIDs remain parseable only for trusted upstream/internal adapters;
// the Laravel↔gateway contract accepts exclusively +E.164 or lid:<digits>.
func NormalizeContractOneToOneAddress(raw string) (OneToOneAddress, error) {
	if !strings.HasPrefix(raw, "+") && !strings.HasPrefix(raw, "lid:") {
		if _, err := NormalizeOneToOneAddress(raw); err != nil {
			return OneToOneAddress{}, err
		}
		return OneToOneAddress{}, ErrRecipientInvalid
	}
	return NormalizeOneToOneAddress(raw)
}

// NormalizeOneToOneJID applies the same allowlist to an upstream event JID.
func NormalizeOneToOneJID(jid types.JID) (OneToOneAddress, error) {
	return normalizeUserJID(jid)
}

// ResolvePN returns a phone-number JID, resolving LID through the resolver for
// the current session. Missing/invalid mappings fail closed without exposing
// the LID or its mapping in the returned error.
func (address OneToOneAddress) ResolvePN(ctx context.Context, resolver PNResolver) (OneToOneAddress, error) {
	normalizedAddress, err := NormalizeOneToOneJID(address.JID)
	if err != nil {
		return OneToOneAddress{}, err
	}
	if normalizedAddress.Kind != address.Kind {
		return OneToOneAddress{}, ErrRecipientInvalid
	}
	address = normalizedAddress
	if address.Kind == AddressPN {
		return address, nil
	}
	if address.Kind != AddressLID || resolver == nil {
		return OneToOneAddress{}, ErrRecipientPNMappingMissing
	}
	mapped, err := resolver.GetPNForLID(ctx, address.JID)
	if err != nil {
		if ctxErr := ctx.Err(); ctxErr != nil {
			return OneToOneAddress{}, ctxErr
		}
		return OneToOneAddress{}, ErrRecipientPNMappingMissing
	}
	normalized, err := NormalizeOneToOneJID(mapped)
	if err != nil {
		return OneToOneAddress{}, err
	}
	if normalized.Kind != AddressPN {
		return OneToOneAddress{}, ErrRecipientPNMappingMissing
	}
	return normalized, nil
}

func normalizeUserJID(jid types.JID) (OneToOneAddress, error) {
	if jid.Server != types.DefaultUserServer && jid.Server != types.HiddenUserServer {
		return OneToOneAddress{}, ErrRecipientScopeNotAllowed
	}
	if jid.Device != 0 || jid.RawAgent != 0 || jid.Integrator != 0 {
		return OneToOneAddress{}, ErrRecipientScopeNotAllowed
	}
	if jid.IsBot() || jid.User == "0" {
		return OneToOneAddress{}, ErrRecipientScopeNotAllowed
	}

	jid = jid.ToNonAD()
	if jid.Server == types.DefaultUserServer {
		if !e164Pattern.MatchString(jid.User) {
			return OneToOneAddress{}, ErrRecipientInvalid
		}
		return OneToOneAddress{JID: jid, Kind: AddressPN, Normalized: "+" + jid.User}, nil
	}
	if !lidPattern.MatchString(jid.User) {
		return OneToOneAddress{}, ErrRecipientInvalid
	}
	return OneToOneAddress{JID: jid, Kind: AddressLID, Normalized: "lid:" + jid.User}, nil
}
