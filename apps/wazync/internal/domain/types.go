package domain

import (
	"encoding/json"
	"errors"
	"regexp"
	"time"
)

var identifierPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$`)

var (
	ErrDigestConflict       = errors.New("identifier already exists with another digest")
	ErrNotFound             = errors.New("record not found")
	ErrProfilePictureHidden = errors.New("profile picture unavailable due to privacy")
	ErrProfilePictureNotSet = errors.New("profile picture not set")
	ErrStateConflict        = errors.New("record state conflict")
	ErrFeatureOff           = errors.New("gateway is disabled")
	ErrSessionAlreadyPaired = errors.New("WhatsApp session already paired")
)

type CommandType string

const (
	CommandProvisionSession          CommandType = "SESSION_PROVISION"
	CommandPairSession               CommandType = "SESSION_PAIR"
	CommandPairPhone                 CommandType = "SESSION_PAIR_PHONE"
	CommandPasskeyRespond            CommandType = "SESSION_PASSKEY_RESPOND"
	CommandPasskeyConfirm            CommandType = "SESSION_PASSKEY_CONFIRM"
	CommandConnectSession            CommandType = "SESSION_CONNECT"
	CommandDisconnectSession         CommandType = "SESSION_DISCONNECT"
	CommandSetPassive                CommandType = "SESSION_SET_PASSIVE"
	CommandLogoutSession             CommandType = "SESSION_LOGOUT"
	CommandSendMessage               CommandType = "MESSAGE_SEND"
	CommandEditMessage               CommandType = "MESSAGE_EDIT"
	CommandRevokeMessage             CommandType = "MESSAGE_REVOKE"
	CommandReactMessage              CommandType = "MESSAGE_REACT"
	CommandVotePoll                  CommandType = "POLL_VOTE"
	CommandMarkMessage               CommandType = "MESSAGE_MARK"
	CommandRequestUnavailableMessage CommandType = "MESSAGE_REQUEST_UNAVAILABLE"
	CommandRetryMedia                CommandType = "MEDIA_RETRY_REQUEST"
	CommandSetPresence               CommandType = "PRESENCE_SET"
	CommandSubscribePresence         CommandType = "PRESENCE_SUBSCRIBE"
	CommandSetChatPresence           CommandType = "CHAT_PRESENCE_SET"
	CommandSetDisappearing           CommandType = "CHAT_DISAPPEARING_SET"
	CommandUpdateChatState           CommandType = "CHAT_STATE_UPDATE"
	CommandUpdateBlocklist           CommandType = "BLOCKLIST_UPDATE"
	CommandUpdatePrivacy             CommandType = "PRIVACY_UPDATE"
	CommandSetDefaultDisappearing    CommandType = "DEFAULT_DISAPPEARING_SET"
	CommandRequestHistorySync        CommandType = "HISTORY_SYNC_REQUEST"
)

func (t CommandType) Valid() bool {
	switch t {
	case CommandProvisionSession, CommandPairSession, CommandPairPhone, CommandPasskeyRespond,
		CommandPasskeyConfirm, CommandConnectSession, CommandDisconnectSession,
		CommandSetPassive, CommandLogoutSession, CommandSendMessage, CommandEditMessage,
		CommandRevokeMessage, CommandReactMessage, CommandVotePoll, CommandMarkMessage,
		CommandRequestUnavailableMessage, CommandRetryMedia, CommandSetPresence, CommandSubscribePresence,
		CommandSetChatPresence, CommandSetDisappearing, CommandUpdateChatState,
		CommandUpdateBlocklist, CommandUpdatePrivacy, CommandSetDefaultDisappearing,
		CommandRequestHistorySync:
		return true
	default:
		return false
	}
}

type Command struct {
	ContractVersion   string          `json:"contract_version"`
	CommandID         string          `json:"command_id"`
	SessionID         string          `json:"session_id"`
	Type              CommandType     `json:"type"`
	ProviderMessageID string          `json:"provider_message_id,omitempty"`
	Payload           json.RawMessage `json:"payload"`
	Digest            string          `json:"-"`
	AcceptedAt        time.Time       `json:"-"`
}

func (c Command) Valid() bool {
	return c.ContractVersion == "v1" &&
		identifierPattern.MatchString(c.CommandID) &&
		identifierPattern.MatchString(c.SessionID) &&
		(c.ProviderMessageID == "" || identifierPattern.MatchString(c.ProviderMessageID)) &&
		c.Type.Valid() && len(c.Payload) > 0
}

type PendingCommand struct {
	Command  Command
	Attempts int
}

type EventType string

const (
	EventMessageReceived       EventType = "MESSAGE_RECEIVED"
	EventMessageStatusChanged  EventType = "MESSAGE_STATUS_CHANGED"
	EventMessageActionReceived EventType = "MESSAGE_ACTION_RECEIVED"
	EventSessionStatusChanged  EventType = "SESSION_STATUS_CHANGED"
	EventPairingUpdated        EventType = "PAIRING_UPDATED"
	EventMediaReady            EventType = "MEDIA_READY"
	EventChatPresenceChanged   EventType = "CHAT_PRESENCE_CHANGED"
	EventPresenceChanged       EventType = "CONTACT_PRESENCE_CHANGED"
	EventContactProfileChanged EventType = "CONTACT_PROFILE_CHANGED"
	EventIdentityChanged       EventType = "CONTACT_IDENTITY_CHANGED"
	EventPrivacyChanged        EventType = "PRIVACY_SETTINGS_CHANGED"
	EventBlocklistChanged      EventType = "BLOCKLIST_CHANGED"
	EventChatStateChanged      EventType = "CHAT_STATE_CHANGED"
	EventHistorySynced         EventType = "HISTORY_SYNCED"
	EventSyncStatusChanged     EventType = "SYNC_STATUS_CHANGED"
	EventMediaRetryUpdated     EventType = "MEDIA_RETRY_UPDATED"
	EventGatewayAlert          EventType = "GATEWAY_ALERT"
)

func (t EventType) Valid() bool {
	switch t {
	case EventMessageReceived, EventMessageStatusChanged, EventMessageActionReceived,
		EventSessionStatusChanged, EventPairingUpdated, EventMediaReady, EventChatPresenceChanged,
		EventPresenceChanged, EventContactProfileChanged, EventIdentityChanged, EventPrivacyChanged,
		EventBlocklistChanged, EventChatStateChanged, EventHistorySynced, EventSyncStatusChanged,
		EventMediaRetryUpdated, EventGatewayAlert:
		return true
	default:
		return false
	}
}

type Event struct {
	ContractVersion string          `json:"contract_version"`
	EventID         string          `json:"gateway_event_id"`
	SessionID       string          `json:"session_id"`
	Type            EventType       `json:"type"`
	OccurredAt      time.Time       `json:"occurred_at"`
	Payload         json.RawMessage `json:"payload"`
	Digest          string          `json:"-"`
}

func (e Event) Valid() bool {
	return e.ContractVersion == "v1" && identifierPattern.MatchString(e.EventID) &&
		identifierPattern.MatchString(e.SessionID) && e.Type.Valid() && !e.OccurredAt.IsZero() && len(e.Payload) > 0
}

type PendingEvent struct {
	Event    Event
	Attempts int
}

type QueryType string

const (
	QueryIsOnWhatsApp        QueryType = "USER_CHECK"
	QueryUserInfo            QueryType = "USER_INFO"
	QueryContactProfiles     QueryType = "CONTACT_PROFILES"
	QueryBusinessProfile     QueryType = "BUSINESS_PROFILE"
	QueryProfilePicture      QueryType = "PROFILE_PICTURE"
	QueryContactQRLink       QueryType = "CONTACT_QR_LINK"
	QueryResolveContactQR    QueryType = "CONTACT_QR_RESOLVE"
	QueryResolveBusinessLink QueryType = "BUSINESS_LINK_RESOLVE"
	QueryBlocklist           QueryType = "BLOCKLIST"
	QueryPrivacySettings     QueryType = "PRIVACY_SETTINGS"
)

func (t QueryType) Valid() bool {
	switch t {
	case QueryIsOnWhatsApp, QueryUserInfo, QueryContactProfiles, QueryBusinessProfile, QueryProfilePicture,
		QueryContactQRLink, QueryResolveContactQR, QueryResolveBusinessLink,
		QueryBlocklist, QueryPrivacySettings:
		return true
	default:
		return false
	}
}

type Query struct {
	ContractVersion string          `json:"contract_version"`
	QueryID         string          `json:"query_id"`
	SessionID       string          `json:"session_id"`
	Type            QueryType       `json:"type"`
	Payload         json.RawMessage `json:"payload"`
}

func (q Query) Valid() bool {
	return q.ContractVersion == "v1" && identifierPattern.MatchString(q.QueryID) &&
		identifierPattern.MatchString(q.SessionID) && q.Type.Valid() && len(q.Payload) > 0
}

type SessionStatus string

const (
	SessionDisconnected SessionStatus = "DISCONNECTED"
	SessionConnecting   SessionStatus = "CONNECTING"
	SessionConnected    SessionStatus = "CONNECTED"
)

func (s SessionStatus) Valid() bool {
	switch s {
	case SessionDisconnected, SessionConnecting, SessionConnected:
		return true
	default:
		return false
	}
}

type SessionLifecycleEvent string

const (
	SessionLifecycleDisconnected         SessionLifecycleEvent = "DISCONNECTED"
	SessionLifecycleManualLoginReconnect SessionLifecycleEvent = "MANUAL_LOGIN_RECONNECT"
	SessionLifecycleLoggedOut            SessionLifecycleEvent = "LOGGED_OUT"
)

type Session struct {
	SessionID        string
	Status           SessionStatus
	DesiredConnected bool
	FencingToken     int64
	ReconnectCount   int
	NextReconnectAt  time.Time
	UpdatedAt        time.Time
}

func (s Session) Valid() bool {
	return identifierPattern.MatchString(s.SessionID) && s.Status.Valid()
}

type Lease struct {
	SessionID    string
	ReplicaID    string
	FencingToken int64
	ExpiresAt    time.Time
}

type PairingUpdate struct {
	Event          string
	Code           string
	ExpiresAt      time.Time
	ErrorCode      string
	PasskeyRequest *PasskeyRequest
}

// PasskeyRequest is the allowlisted projection of WebAuthn public-key options.
// Extensions and the raw upstream event are intentionally not exposed.
type PasskeyRequest struct {
	RequestID         string              `json:"request_id"`
	Challenge         string              `json:"challenge"`
	TimeoutMS         int                 `json:"timeout_ms"`
	RelyingPartyID    string              `json:"relying_party_id"`
	UserVerification  string              `json:"user_verification,omitempty"`
	AllowedCredential []PasskeyCredential `json:"allowed_credentials,omitempty"`
}

type PasskeyCredential struct {
	ID         string   `json:"id"`
	Type       string   `json:"type"`
	Transports []string `json:"transports,omitempty"`
}

type Health struct {
	Status          string `json:"status"`
	Enabled         bool   `json:"enabled"`
	Store           string `json:"store"`
	PendingCommands int64  `json:"pending_commands"`
	PendingEvents   int64  `json:"pending_events"`
}

type Metrics struct {
	PendingCommands int64
	PendingEvents   int64
	FailedEvents    int64
	ActiveSessions  int64
	ActiveLeases    int64
	SpoolFiles      int64
}

// MediaRetryState is private gateway state. Descriptor contains the minimal
// protobuf media message required by the pinned whatsmeow retry API and is
// encrypted by durable stores; it never enters an outbound gateway event.
type MediaRetryState struct {
	SessionID  string
	MessageID  string
	Descriptor []byte
	Generation int
	Attempts   int
	UpdatedAt  time.Time
	ExpiresAt  time.Time
}
