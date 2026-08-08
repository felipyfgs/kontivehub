package catalog

// OutboundBuilderEntry is the fail-closed inventory for a product-level
// outbound family or variant. DescriptorAvailable establishes only that the
// pinned protobuf exposes the primitive; BuilderEnabled additionally requires
// a tested local builder contract. Native albums require separate live
// interoperability evidence before their builder can be enabled.
type OutboundBuilderEntry struct {
	Capability          string
	DescriptorFields    []DescriptorField
	DescriptorAvailable bool
	BuilderEnabled      bool
	ContractTested      bool
	UnavailableReason   string
	Evidence            string
}

// DescriptorField pins one field required by an outbound primitive. The
// message name is resolved from the pinned waE2E file descriptor in tests so
// a whatsmeow upgrade cannot silently change this inventory.
type DescriptorField struct {
	Message string
	Number  int
	Name    string
}

const (
	OutboundContactSingle = "contact_single"
	OutboundMedia         = "media"
	OutboundPTT           = "ptt"
	OutboundContactArray  = "contacts_array"
	OutboundNativeAlbum   = "album_native"
	OutboundGIFPlayback   = "gif_playback"
	OutboundPTV           = "ptv"
	OutboundEvent         = "event"
	OutboundViewOnce      = "view_once"
)

const (
	UnavailableContactArrayBuilder = "CONTACTS_ARRAY_BUILDER_UNIMPLEMENTED"
	UnavailableNativeAlbum         = "NATIVE_ALBUM_INTEROPERABILITY_UNVERIFIED"
	UnavailableGIFPlaybackBuilder  = "GIF_PLAYBACK_BUILDER_UNIMPLEMENTED"
	UnavailablePTVBuilder          = "PTV_BUILDER_UNIMPLEMENTED"
	UnavailableEventBuilder        = "EVENT_BUILDER_UNIMPLEMENTED"
	UnavailableViewOnceBuilder     = "VIEW_ONCE_BUILDER_UNIMPLEMENTED"
)

// OutboundBuilders is intentionally static. In particular, descriptor
// availability must never automatically enable an outbound variant.
var OutboundBuilders = []OutboundBuilderEntry{
	{
		Capability:          OutboundContactSingle,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 4, Name: "contactMessage"}},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go",
	},
	{
		Capability:          OutboundMedia,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 3, Name: "imageMessage"}, {Message: "Message", Number: 8, Name: "audioMessage"}, {Message: "Message", Number: 9, Name: "videoMessage"}, {Message: "Message", Number: 7, Name: "documentMessage"}},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go; internal/protocol/whatsmeow_test.go",
	},
	{
		Capability:          OutboundPTT,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 8, Name: "audioMessage"}, {Message: "AudioMessage", Number: 6, Name: "PTT"}},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go",
	},
	{
		Capability:          OutboundContactArray,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 13, Name: "contactsArrayMessage"}},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go",
	},
	{
		Capability:          OutboundNativeAlbum,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 83, Name: "albumMessage"}},
		DescriptorAvailable: true,
		UnavailableReason:   UnavailableNativeAlbum,
		Evidence:            "descriptor only; native album interoperability has not been proven",
	},
	{
		Capability:          OutboundGIFPlayback,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 9, Name: "videoMessage"}, {Message: "VideoMessage", Number: 8, Name: "gifPlayback"}},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go",
	},
	{
		Capability:          OutboundPTV,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 66, Name: "ptvMessage"}},
		DescriptorAvailable: true,
		UnavailableReason:   UnavailablePTVBuilder,
		Evidence:            "descriptor only; no PTV builder contract",
	},
	{
		Capability:          OutboundEvent,
		DescriptorFields:    []DescriptorField{{Message: "Message", Number: 75, Name: "eventMessage"}},
		DescriptorAvailable: true,
		UnavailableReason:   UnavailableEventBuilder,
		Evidence:            "descriptor only; no bounded event builder contract",
	},
	{
		Capability: OutboundViewOnce,
		DescriptorFields: []DescriptorField{
			{Message: "Message", Number: 37, Name: "viewOnceMessage"},
			{Message: "Message", Number: 55, Name: "viewOnceMessageV2"},
			{Message: "Message", Number: 59, Name: "viewOnceMessageV2Extension"},
		},
		DescriptorAvailable: true,
		BuilderEnabled:      true,
		ContractTested:      true,
		Evidence:            "internal/protocol/typed_messages_test.go",
	},
}
