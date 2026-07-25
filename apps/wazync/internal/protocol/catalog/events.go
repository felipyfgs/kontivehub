package catalog

import (
	"reflect"

	"go.mau.fi/whatsmeow/types/events"
)

type EventEntry struct {
	Entry
	Type reflect.Type
}

type eventDefinition struct {
	name   string
	typeOf reflect.Type
}

func eventOf[T any](name string) eventDefinition {
	return eventDefinition{name: name, typeOf: reflect.TypeOf((*T)(nil)).Elem()}
}

var EventTypes = buildEventTypes()

func buildEventTypes() map[string]EventEntry {
	result := make(map[string]EventEntry, 74)
	register := func(entry Entry, definitions ...eventDefinition) {
		for _, definition := range definitions {
			if _, exists := result[definition.name]; exists {
				panic("duplicate whatsmeow event in catalog: " + definition.name)
			}
			entry.Source = UpstreamCommit
			result[definition.name] = EventEntry{Entry: entry, Type: definition.typeOf}
		}
	}

	register(Entry{
		Scope: "session", Disposition: Baseline, Owner: "adicionar-comunicacao-whatsapp-nativa",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
	},
		eventOf[events.Connected]("Connected"), eventOf[events.Disconnected]("Disconnected"),
		eventOf[events.LoggedOut]("LoggedOut"),
	)
	register(Entry{
		Scope: "session", Disposition: Implemented, Owner: "2.8",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
	},
		eventOf[events.QR]("QR"), eventOf[events.PairSuccess]("PairSuccess"),
		eventOf[events.PairError]("PairError"), eventOf[events.PairPasskeyRequest]("PairPasskeyRequest"),
		eventOf[events.PairPasskeyError]("PairPasskeyError"),
		eventOf[events.PairPasskeyConfirmation]("PairPasskeyConfirmation"),
		eventOf[events.QRScannedWithoutMultidevice]("QRScannedWithoutMultidevice"),
		eventOf[events.KeepAliveTimeout]("KeepAliveTimeout"),
		eventOf[events.KeepAliveRestored]("KeepAliveRestored"),
		eventOf[events.StreamReplaced]("StreamReplaced"),
		eventOf[events.ManualLoginReconnect]("ManualLoginReconnect"),
		eventOf[events.TemporaryBan]("TemporaryBan"),
		eventOf[events.ConnectFailure]("ConnectFailure"),
		eventOf[events.ClientOutdated]("ClientOutdated"),
		eventOf[events.CATRefreshError]("CATRefreshError"),
		eventOf[events.StreamError]("StreamError"),
	)

	register(Entry{
		Scope: "1:1 message", Disposition: Baseline, Owner: "adicionar-comunicacao-whatsapp-nativa",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
	}, eventOf[events.Message]("Message"), eventOf[events.Receipt]("Receipt"))
	register(Entry{
		Scope: "1:1 message or sync", Disposition: Implemented, Owner: "2.8",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go; internal/protocol/recovery_test.go",
	},
		eventOf[events.HistorySync]("HistorySync"),
		eventOf[events.UndecryptableMessage]("UndecryptableMessage"),
		eventOf[events.OfflineSyncPreview]("OfflineSyncPreview"),
		eventOf[events.OfflineSyncCompleted]("OfflineSyncCompleted"),
		eventOf[events.MediaRetry]("MediaRetry"), eventOf[events.MediaRetryError]("MediaRetryError"),
	)
	register(Entry{
		Scope: "1:1 presence, profile or security", Disposition: Implemented, Owner: "2.8",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
	},
		eventOf[events.ChatPresence]("ChatPresence"), eventOf[events.Presence]("Presence"),
		eventOf[events.Picture]("Picture"), eventOf[events.UserAbout]("UserAbout"),
		eventOf[events.IdentityChange]("IdentityChange"),
		eventOf[events.PrivacySettings]("PrivacySettings"),
		eventOf[events.Blocklist]("Blocklist"), eventOf[events.BlocklistChange]("BlocklistChange"),
	)
	register(Entry{
		Scope: "1:1 app-state", Disposition: Implemented, Owner: "2.8",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go",
	},
		eventOf[events.Contact]("Contact"), eventOf[events.PushName]("PushName"),
		eventOf[events.BusinessName]("BusinessName"), eventOf[events.Pin]("Pin"),
		eventOf[events.Star]("Star"), eventOf[events.DeleteForMe]("DeleteForMe"),
		eventOf[events.Mute]("Mute"), eventOf[events.Archive]("Archive"),
		eventOf[events.MarkChatAsRead]("MarkChatAsRead"), eventOf[events.ClearChat]("ClearChat"),
		eventOf[events.DeleteChat]("DeleteChat"),
		eventOf[events.LabelAssociationChat]("LabelAssociationChat"),
		eventOf[events.LabelAssociationMessage]("LabelAssociationMessage"),
		eventOf[events.AppStateSyncComplete]("AppStateSyncComplete"),
		eventOf[events.AppStateSyncError]("AppStateSyncError"),
	)
	register(Entry{
		Scope: "account-level internal event", Disposition: Internal, Owner: "2.8",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go; consumed without raw serialization",
	},
		eventOf[events.PushNameSetting]("PushNameSetting"),
		eventOf[events.UnarchiveChatsSetting]("UnarchiveChatsSetting"),
		eventOf[events.LabelEdit]("LabelEdit"), eventOf[events.AppState]("AppState"),
	)
	register(Entry{
		Scope: "status or broadcast", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "internal/protocol/event_bridge.go; internal/protocol/event_bridge_test.go; status is outside 1:1",
	}, eventOf[events.UserStatusMute]("UserStatusMute"))

	register(Entry{
		Scope: "group or community", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "group events are rejected before projection",
	}, eventOf[events.JoinedGroup]("JoinedGroup"), eventOf[events.GroupInfo]("GroupInfo"))
	register(Entry{
		Scope: "newsletter or channel", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "newsletter events are rejected before projection",
	},
		eventOf[events.NewsletterMessageMeta]("NewsletterMessageMeta"),
		eventOf[events.NewsletterJoin]("NewsletterJoin"), eventOf[events.NewsletterLeave]("NewsletterLeave"),
		eventOf[events.NewsletterMuteChange]("NewsletterMuteChange"),
		eventOf[events.NewsletterLiveUpdate]("NewsletterLiveUpdate"),
	)
	register(Entry{
		Scope: "call", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "call signaling is outside the message-focused scope",
	},
		eventOf[events.CallOffer]("CallOffer"), eventOf[events.CallAccept]("CallAccept"),
		eventOf[events.CallPreAccept]("CallPreAccept"), eventOf[events.CallTransport]("CallTransport"),
		eventOf[events.CallOfferNotice]("CallOfferNotice"),
		eventOf[events.CallRelayLatency]("CallRelayLatency"),
		eventOf[events.CallTerminate]("CallTerminate"), eventOf[events.CallReject]("CallReject"),
		eventOf[events.UnknownCallEvent]("UnknownCallEvent"),
	)
	register(Entry{
		Scope: "FB, bot or account notification", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "not a supported 1:1 conversation projection",
	},
		eventOf[events.FBMessage]("FBMessage"),
		eventOf[events.MexNotificationData]("MexNotificationData"),
		eventOf[events.NotifyAccountReachoutTimelock]("NotifyAccountReachoutTimelock"),
	)

	return result
}
