// Package catalog keeps the pinned whatsmeow surface explicit and executable.
//
// It is intentionally independent from the public gateway contract: some upstream
// primitives are composed internally and others are excluded by product policy.
package catalog

const (
	UpstreamModule  = "go.mau.fi/whatsmeow"
	UpstreamVersion = "v0.0.0-20260721154117-8b4a8ba0d318"
	UpstreamCommit  = "8b4a8ba0d31877b19331748d75d96f338c7982d3"
	WuzAPICommit    = "70642149a0e8a81d49caa640f557217e03e09729"
)

type Disposition string

const (
	Baseline    Disposition = "BASELINE"
	Implemented Disposition = "IMPLEMENTED"
	Internal    Disposition = "INTERNAL"
	Excluded    Disposition = "EXCLUDED"
	Deprecated  Disposition = "DEPRECATED"
)

// Entry records why an upstream symbol exists in the coverage plan and where
// its behavior is owned. Evidence points to concrete implementation/tests or
// to the documented reason for an exclusion, deprecation or composition.
type Entry struct {
	Source      string
	Scope       string
	Disposition Disposition
	Owner       string
	Evidence    string
	Reference   string
}

var ClientMethods = buildClientMethods()

func buildClientMethods() map[string]Entry {
	result := make(map[string]Entry, 135)
	register := func(entry Entry, names ...string) {
		for _, name := range names {
			if _, exists := result[name]; exists {
				panic("duplicate whatsmeow client method in catalog: " + name)
			}
			entry.Source = UpstreamCommit
			result[name] = entry
		}
	}
	baseline := Entry{
		Scope:       "1:1",
		Disposition: Baseline,
		Owner:       "adicionar-comunicacao-whatsapp-nativa",
		Evidence:    "internal/protocol/whatsmeow.go; internal/protocol/event_bridge.go; internal/protocol/whatsmeow_test.go; internal/protocol/event_bridge_test.go",
	}
	register(baseline,
		"AddEventHandler", "Connect", "Disconnect", "IsConnected", "Logout",
		"GetQRChannel", "SendMessage", "Upload", "DownloadToFile",
	)

	register(Entry{
		Scope: "1:1 app-state", Disposition: Implemented, Owner: "2.6",
		Evidence: "internal/protocol/account_policy.go; internal/protocol/account_policy_test.go",
	}, "FetchAppState", "MarkNotDirty", "SendAppState")

	register(Entry{
		Scope: "session", Disposition: Implemented, Owner: "2.1",
		Evidence: "internal/protocol/session.go; internal/protocol/client_settings.go; internal/protocol/device_resolver.go; internal/protocol/session_test.go; internal/protocol/event_bridge_test.go",
	},
		"AddEventHandlerWithSuccessStatus", "ConnectContext", "IsLoggedIn", "ParseWebMessage",
		"RemoveEventHandler", "ResetConnection", "SetMaxParallelRetryReceiptHandling",
		"SetMediaHTTPClient", "SetPreLoginHTTPClient", "SetWebsocketHTTPClient",
		"SetProxyAddress", "SetSOCKSProxy", "WaitForConnection", "SetPassive",
	)
	register(Entry{
		Scope: "pairing", Disposition: Implemented, Owner: "2.1",
		Evidence:  "internal/protocol/session.go; internal/protocol/session_test.go",
		Reference: "WuzAPI PairPhone flow",
	}, "PairPhone", "SendPasskeyResponse", "SendPasskeyConfirmation")

	register(Entry{
		Scope: "1:1 message action", Disposition: Implemented, Owner: "2.3",
		Evidence:  "internal/protocol/actions.go; internal/protocol/recovery.go; internal/protocol/actions_test.go; internal/protocol/recovery_test.go",
		Reference: "WuzAPI edit/revoke handlers",
	},
		"BuildMessageKey", "BuildReaction", "BuildRevoke", "BuildEdit",
		"BuildUnavailableMessageRequest", "BuildHistorySyncRequest", "SendPeerMessage",
		"SetDisappearingTimer",
	)
	register(Entry{
		Scope: "1:1 poll or encrypted action", Disposition: Implemented, Owner: "2.3",
		Evidence:  "internal/protocol/actions.go; internal/protocol/typed_messages.go; internal/protocol/event_bridge.go; internal/protocol/actions_test.go; internal/protocol/typed_messages_test.go; internal/protocol/event_bridge_test.go",
		Reference: "WuzAPI poll and edit decoding",
	}, "BuildPollCreation", "BuildPollVote", "DecryptPollVote", "DecryptSecretEncryptedMessage")

	register(Entry{
		Scope: "1:1 presence or receipt", Disposition: Implemented, Owner: "2.4",
		Evidence: "internal/protocol/presence.go; internal/protocol/actions.go; internal/protocol/presence_test.go; internal/protocol/actions_test.go",
	},
		"SendPresence", "SubscribePresence", "SendChatPresence", "MarkRead",
		"SetForceActiveDeliveryReceipts", "SendProtocolMessageReceipt",
	)

	register(Entry{
		Scope: "1:1 media", Disposition: Implemented, Owner: "2.7",
		Evidence: "internal/protocol/typed_messages.go; internal/protocol/recovery.go; internal/protocol/typed_messages_test.go; internal/protocol/recovery_test.go",
	}, "UploadReader", "DeleteMedia", "DownloadThumbnail", "FetchStickerPack", "SendMediaRetryReceipt")
	register(Entry{
		Scope: "1:1 history", Disposition: Implemented, Owner: "2.7",
		Evidence: "internal/protocol/recovery.go; internal/protocol/event_bridge.go; internal/protocol/recovery_test.go; internal/protocol/event_bridge_test.go",
	}, "DownloadHistorySync", "SendHistorySyncServerErrorReceipt")

	register(Entry{
		Scope: "1:1 contact query", Disposition: Implemented, Owner: "2.5",
		Evidence:  "internal/protocol/queries.go; internal/protocol/queries_test.go",
		Reference: "WuzAPI user check, user info and avatar handlers",
	},
		"IsOnWhatsApp", "GetUserInfo", "GetBusinessProfile", "GetProfilePictureInfo",
		"GetContactQRLink", "ResolveContactQRLink", "ResolveBusinessMessageLink",
	)
	register(Entry{
		Scope: "1:1 account policy", Disposition: Implemented, Owner: "2.6",
		Evidence:  "internal/protocol/account_policy.go; internal/protocol/queries.go; internal/protocol/account_policy_test.go; internal/protocol/queries_test.go",
		Reference: "WuzAPI blocklist LID-to-PN and privacy handlers",
	},
		"GetBlocklist", "UpdateBlocklist", "TryFetchPrivacySettings", "GetPrivacySettings",
		"SetPrivacySetting", "SetDefaultDisappearingTimer",
	)

	register(Entry{
		Scope: "internal session primitive", Disposition: Internal, Owner: "2.1",
		Evidence: "internal/protocol/client_settings.go; internal/protocol/device_resolver.go; internal/protocol/session_test.go; internal/protocol/event_bridge_test.go",
	}, "RemoveEventHandlers", "SetProxy", "StoreLIDPNMapping")
	register(Entry{
		Scope: "internal message primitive", Disposition: Internal, Owner: "2.2",
		Evidence: "internal/domain/contract.go; internal/domain/contract_test.go; provider message IDs come from Laravel",
	}, "GenerateMessageID")
	register(Entry{
		Scope: "internal poll primitive", Disposition: Internal, Owner: "2.3",
		Evidence: "internal/protocol/actions.go; internal/protocol/actions_test.go; composed by BuildPollVote",
	}, "EncryptPollVote")
	register(Entry{
		Scope: "internal media primitive", Disposition: Internal, Owner: "2.7",
		Evidence: "internal/protocol/recovery.go; internal/protocol/event_bridge.go; internal/protocol/recovery_test.go; internal/protocol/event_bridge_test.go",
	},
		"Download", "DownloadMediaWithOnlyPath", "DownloadMediaWithOnlyPathToFile",
		"DownloadMediaWithPath", "DownloadMediaWithPathToFile",
	)
	register(Entry{
		Scope: "internal E2E fanout", Disposition: Internal, Owner: "2.5",
		Evidence: "internal/protocol/queries.go; internal/protocol/queries_test.go; device JIDs never enter query results",
	}, "GetUserDevices", "GetUserDevicesContext")

	register(Entry{
		Scope: "deprecated upstream API", Disposition: Deprecated, Owner: "policy",
		Evidence: "forbidden in new code; use the supported replacement documented in catalog.md",
	}, "DangerousInternals", "RevokeMessage", "DownloadAny")

	register(Entry{
		Scope: "status or broadcast", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "user requested conversations 1:1 only",
	}, "GetStatusPrivacy", "SetStatusMessage")
	register(Entry{
		Scope: "group or community", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "groups and communities are explicitly out of scope",
	},
		"CreateGroup", "GetGroupInfo", "GetGroupInfoFromInvite", "GetGroupInfoFromLink",
		"GetGroupInviteLink", "GetGroupRequestParticipants", "GetJoinedGroups",
		"GetLinkedGroupsParticipants", "GetSubGroups", "JoinGroupWithInvite",
		"JoinGroupWithLink", "LeaveGroup", "LinkGroup", "UnlinkGroup",
		"SetGroupAnnounce", "SetGroupDescription", "SetGroupJoinApprovalMode",
		"SetGroupLocked", "SetGroupMemberAddMode", "SetGroupName", "SetGroupPhoto",
		"SetGroupTopic", "UpdateGroupParticipants", "UpdateGroupRequestParticipants",
	)
	register(Entry{
		Scope: "newsletter or channel", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "channels and newsletters are explicitly out of scope",
	},
		"AcceptTOSNotice", "CreateNewsletter", "FollowNewsletter", "UnfollowNewsletter",
		"GetNewsletterInfo", "GetNewsletterInfoWithInvite", "GetNewsletterMessageUpdates",
		"GetNewsletterMessages", "GetSubscribedNewsletters", "NewsletterMarkViewed",
		"NewsletterSendReaction", "NewsletterSubscribeLiveUpdates", "NewsletterToggleMute",
		"UploadNewsletter", "UploadNewsletterReader",
	)
	register(Entry{
		Scope: "FB, bot or community encryption", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "FB/Armadillo, bots and community announcement features are outside 1:1 chat",
	},
		"SendFBMessage", "DownloadFB", "DownloadFBToFile", "GetBotListV2", "GetBotProfiles",
		"DecryptReaction", "EncryptReaction", "DecryptComment", "EncryptComment",
	)
	register(Entry{
		Scope: "mobile push", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "the gateway is a long-lived companion and does not register mobile push",
	}, "GetServerPushNotificationConfig", "RegisterForPushNotifications")
	register(Entry{
		Scope: "call", Disposition: Excluded, Owner: "scope-policy",
		Evidence: "call media is outside the message-focused 1:1 scope",
	}, "RejectCall")

	return result
}
