package catalog

import (
	"strings"
	"testing"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"google.golang.org/protobuf/reflect/protoreflect"
)

func TestOutboundBuilderInventoryMatchesPinnedDescriptor(t *testing.T) {
	t.Parallel()

	file := (&waE2E.Message{}).ProtoReflect().Descriptor().ParentFile()
	seen := make(map[string]bool, len(OutboundBuilders))
	for _, entry := range OutboundBuilders {
		if entry.Capability == "" || seen[entry.Capability] {
			t.Errorf("outbound capability must be unique and non-empty: %#v", entry)
		}
		seen[entry.Capability] = true
		if !entry.DescriptorAvailable {
			t.Errorf("%s must record the pinned descriptor result", entry.Capability)
		}
		if entry.Evidence == "" {
			t.Errorf("%s has no inventory evidence", entry.Capability)
		}

		for _, required := range entry.DescriptorFields {
			message := file.Messages().ByName(protoreflect.Name(required.Message))
			if message == nil {
				t.Errorf("%s requires missing descriptor message %s", entry.Capability, required.Message)
				continue
			}
			field := message.Fields().ByName(protoreflect.Name(required.Name))
			if field == nil || int(field.Number()) != required.Number {
				t.Errorf("%s requires missing descriptor field %s.%s", entry.Capability, required.Message, required.Name)
			}
		}
	}
}

func TestOutboundBuildersFailClosedUntilContractIsProven(t *testing.T) {
	t.Parallel()

	disabled := map[string]string{
		OutboundNativeAlbum: UnavailableNativeAlbum,
		OutboundPTV:         UnavailablePTVBuilder,
		OutboundEvent:       UnavailableEventBuilder,
	}
	for _, entry := range OutboundBuilders {
		if reason, mustBeDisabled := disabled[entry.Capability]; mustBeDisabled {
			if entry.BuilderEnabled || entry.ContractTested || entry.UnavailableReason != reason {
				t.Errorf("%s must remain fail-closed: %#v", entry.Capability, entry)
			}
			continue
		}
		if entry.BuilderEnabled != entry.ContractTested {
			t.Errorf("%s can only be enabled with builder-contract evidence: %#v", entry.Capability, entry)
		}
		if entry.BuilderEnabled && entry.UnavailableReason != "" {
			t.Errorf("enabled %s cannot have an unavailable reason", entry.Capability)
		}
	}

	for _, entry := range OutboundBuilders {
		if entry.BuilderEnabled || entry.UnavailableReason == "" {
			continue
		}
		if strings.ToUpper(entry.UnavailableReason) != entry.UnavailableReason {
			t.Errorf("%s has non-stable unavailable reason %q", entry.Capability, entry.UnavailableReason)
		}
	}
}
