package protocol

import (
	"context"
	"errors"
	"strings"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

type presenceClient interface {
	WhatsMeowClient
	SendPresence(context.Context, types.Presence) error
	SubscribePresence(context.Context, types.JID) error
	SendChatPresence(context.Context, types.JID, types.ChatPresence, types.ChatPresenceMedia) error
	SetForceActiveDeliveryReceipts(bool)
}

var _ presenceClient = (*whatsmeow.Client)(nil)

func (a *WhatsMeowAdapter) SetPresence(
	ctx context.Context,
	sessionID string,
	payload domain.PresencePayload,
) error {
	client, err := a.readyPresenceClient(sessionID)
	if err != nil {
		return err
	}
	if payload.ForceActiveDeliveryReceipts != nil {
		client.SetForceActiveDeliveryReceipts(*payload.ForceActiveDeliveryReceipts)
	}
	switch strings.ToUpper(strings.TrimSpace(payload.Presence)) {
	case "AVAILABLE":
		return client.SendPresence(ctx, types.PresenceAvailable)
	case "UNAVAILABLE":
		return client.SendPresence(ctx, types.PresenceUnavailable)
	default:
		return errors.New("presence must be AVAILABLE or UNAVAILABLE")
	}
}

func (a *WhatsMeowAdapter) SubscribeContactPresence(
	ctx context.Context,
	sessionID string,
	payload domain.ContactPresencePayload,
) error {
	client, err := a.readyPresenceClient(sessionID)
	if err != nil {
		return err
	}
	_, jid, err := a.readyRecipient(sessionID, payload.To)
	if err != nil {
		return err
	}
	return client.SubscribePresence(ctx, jid)
}

func (a *WhatsMeowAdapter) SetChatPresence(
	ctx context.Context,
	sessionID string,
	payload domain.ChatPresencePayload,
) error {
	client, err := a.readyPresenceClient(sessionID)
	if err != nil {
		return err
	}
	_, jid, err := a.readyRecipient(sessionID, payload.To)
	if err != nil {
		return err
	}
	var state types.ChatPresence
	switch strings.ToUpper(strings.TrimSpace(payload.Presence)) {
	case "COMPOSING", "RECORDING":
		state = types.ChatPresenceComposing
	case "PAUSED":
		state = types.ChatPresencePaused
	default:
		return errors.New("chat presence must be COMPOSING, RECORDING or PAUSED")
	}
	media := types.ChatPresenceMediaText
	if strings.EqualFold(payload.Presence, "RECORDING") || strings.EqualFold(payload.Media, "AUDIO") {
		media = types.ChatPresenceMediaAudio
	} else if payload.Media != "" && !strings.EqualFold(payload.Media, "TEXT") {
		return errors.New("chat presence media must be TEXT or AUDIO")
	}
	return client.SendChatPresence(ctx, jid, state, media)
}

func (a *WhatsMeowAdapter) readyPresenceClient(sessionID string) (presenceClient, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, err
	}
	if !client.IsConnected() {
		return nil, errors.New("WhatsApp session is not connected")
	}
	presence, ok := client.(presenceClient)
	if !ok {
		return nil, errors.New("WhatsApp client does not support presence")
	}
	return presence, nil
}
