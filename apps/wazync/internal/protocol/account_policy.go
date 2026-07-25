package protocol

import (
	"context"
	"errors"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/appstate"
	"go.mau.fi/whatsmeow/proto/waCommon"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
)

type accountPolicyClient interface {
	WhatsMeowClient
	UpdateBlocklist(context.Context, types.JID, events.BlocklistChangeAction) (*types.Blocklist, error)
	SetPrivacySetting(context.Context, types.PrivacySettingType, types.PrivacySetting) (types.PrivacySettings, error)
	SetDefaultDisappearingTimer(context.Context, time.Duration) error
	SendAppState(context.Context, appstate.PatchInfo) error
	FetchAppState(context.Context, appstate.WAPatchName, bool, bool) error
	MarkNotDirty(context.Context, string, time.Time) error
	BuildMessageKey(types.JID, types.JID, types.MessageID) *waCommon.MessageKey
}

var _ accountPolicyClient = (*whatsmeow.Client)(nil)

// privacyMatrix is based on the whatsmeow value matrix and the round-trip
// limitation documented by WuzAPI. Group/status/call settings remain excluded,
// and names not updated by the pinned upstream cache fail closed.
var privacyMatrix = map[types.PrivacySettingType]map[types.PrivacySetting]bool{
	types.PrivacySettingTypeLastSeen: {
		types.PrivacySettingAll: true, types.PrivacySettingContacts: true,
		types.PrivacySettingContactBlacklist: true, types.PrivacySettingNone: true,
	},
	types.PrivacySettingTypeProfile: {
		types.PrivacySettingAll: true, types.PrivacySettingContacts: true,
		types.PrivacySettingContactBlacklist: true, types.PrivacySettingNone: true,
	},
	types.PrivacySettingTypeReadReceipts: {
		types.PrivacySettingAll: true, types.PrivacySettingNone: true,
	},
	types.PrivacySettingTypeOnline: {
		types.PrivacySettingAll: true, types.PrivacySettingMatchLastSeen: true,
	},
}

func (a *WhatsMeowAdapter) UpdateBlocklist(
	ctx context.Context,
	sessionID string,
	payload domain.BlocklistUpdatePayload,
) error {
	client, err := a.readyAccountPolicyClient(sessionID)
	if err != nil {
		return err
	}
	address, err := NormalizeOneToOneAddress(payload.To)
	if err != nil {
		return err
	}
	address, err = resolveBlocklistPhone(ctx, client, address)
	if err != nil {
		return err
	}
	var action events.BlocklistChangeAction
	switch strings.ToUpper(strings.TrimSpace(payload.Action)) {
	case "BLOCK":
		action = events.BlocklistChangeActionBlock
	case "UNBLOCK":
		action = events.BlocklistChangeActionUnblock
	default:
		return errors.New("blocklist action must be BLOCK or UNBLOCK")
	}
	_, err = client.UpdateBlocklist(ctx, address.JID, action)
	return err
}

func (a *WhatsMeowAdapter) UpdatePrivacy(
	ctx context.Context,
	sessionID string,
	payload domain.PrivacyUpdatePayload,
) error {
	client, err := a.readyAccountPolicyClient(sessionID)
	if err != nil {
		return err
	}
	name := types.PrivacySettingType(strings.ToLower(strings.TrimSpace(payload.Name)))
	value := types.PrivacySetting(strings.ToLower(strings.TrimSpace(payload.Value)))
	allowed, exists := privacyMatrix[name]
	if !exists || !allowed[value] {
		return errors.New("privacy name/value combination is not allowed")
	}
	_, err = client.SetPrivacySetting(ctx, name, value)
	return err
}

func (a *WhatsMeowAdapter) SetDefaultDisappearing(
	ctx context.Context,
	sessionID string,
	payload domain.DefaultDisappearingPayload,
) error {
	client, err := a.readyAccountPolicyClient(sessionID)
	if err != nil {
		return err
	}
	timer := time.Duration(payload.TimerSeconds) * time.Second
	if !validDisappearingTimer(timer) {
		return errors.New("unsupported default disappearing timer")
	}
	return client.SetDefaultDisappearingTimer(ctx, timer)
}

func (a *WhatsMeowAdapter) UpdateChatState(
	ctx context.Context,
	sessionID string,
	payload domain.ChatStatePayload,
) error {
	client, err := a.readyAccountPolicyClient(sessionID)
	if err != nil {
		return err
	}
	action := strings.ToUpper(strings.TrimSpace(payload.Action))
	if action == "SYNC" {
		for _, name := range appstate.AllPatchNames {
			if err := client.FetchAppState(ctx, name, true, false); err != nil {
				return err
			}
		}
		return nil
	}
	if action == "MARK_CLEAN" {
		timestamp := time.Unix(payload.Timestamp, 0)
		if payload.Timestamp <= 0 {
			return errors.New("mark-clean timestamp is required")
		}
		return client.MarkNotDirty(ctx, "account_sync", timestamp)
	}
	address, err := NormalizeOneToOneAddress(payload.To)
	if err != nil {
		return err
	}
	timestamp := time.Time{}
	if payload.Timestamp > 0 {
		timestamp = time.Unix(payload.Timestamp, 0)
	}
	var key *waCommon.MessageKey
	if payload.TargetMessageID != "" {
		sender, err := actionSender(payload.Sender)
		if err != nil {
			return err
		}
		key = client.BuildMessageKey(address.JID, sender, types.MessageID(payload.TargetMessageID))
	}

	var patch appstate.PatchInfo
	switch action {
	case "ARCHIVE":
		patch = appstate.BuildArchive(address.JID, payload.Value, timestamp, key)
	case "MUTE":
		patch = appstate.BuildMute(address.JID, payload.Value, time.Duration(payload.DurationSeconds)*time.Second)
	case "PIN":
		patch = appstate.BuildPin(address.JID, payload.Value)
	case "STAR":
		if payload.TargetMessageID == "" {
			return errors.New("star target message is required")
		}
		sender, err := actionSender(payload.Sender)
		if err != nil {
			return err
		}
		patch = appstate.BuildStar(
			address.JID, sender, types.MessageID(payload.TargetMessageID), payload.FromMe, payload.Value,
		)
	case "MARK_READ":
		patch = appstate.BuildMarkChatAsRead(address.JID, payload.Value, timestamp, key)
	case "DELETE_CHAT":
		patch = appstate.BuildDeleteChat(address.JID, timestamp, key, payload.DeleteMedia)
	default:
		return errors.New("unsupported chat-state action")
	}
	return client.SendAppState(ctx, patch)
}

func (a *WhatsMeowAdapter) readyAccountPolicyClient(sessionID string) (accountPolicyClient, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, err
	}
	if !client.IsConnected() {
		return nil, errors.New("WhatsApp session is not connected")
	}
	policy, ok := client.(accountPolicyClient)
	if !ok {
		return nil, errors.New("WhatsApp client does not support account policy operations")
	}
	return policy, nil
}

func resolveBlocklistPhone(
	ctx context.Context,
	client WhatsMeowClient,
	address OneToOneAddress,
) (OneToOneAddress, error) {
	if address.Kind == AddressPN {
		return address, nil
	}
	if resolver, ok := client.(PNResolver); ok {
		return address.ResolvePN(ctx, resolver)
	}
	concrete, ok := client.(*whatsmeow.Client)
	if !ok || concrete.Store == nil || concrete.Store.LIDs == nil {
		return OneToOneAddress{}, ErrRecipientPNMappingMissing
	}
	pn, err := concrete.Store.LIDs.GetPNForLID(ctx, address.JID)
	if err != nil || pn.IsEmpty() {
		return OneToOneAddress{}, ErrRecipientPNMappingMissing
	}
	return NormalizeOneToOneJID(pn)
}

func validDisappearingTimer(timer time.Duration) bool {
	switch timer {
	case whatsmeow.DisappearingTimerOff, whatsmeow.DisappearingTimer24Hours,
		whatsmeow.DisappearingTimer7Days, whatsmeow.DisappearingTimer90Days:
		return true
	default:
		return false
	}
}
