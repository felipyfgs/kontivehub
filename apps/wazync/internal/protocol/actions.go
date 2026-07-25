package protocol

import (
	"context"
	"errors"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

type actionClient interface {
	WhatsMeowClient
	BuildEdit(types.JID, types.MessageID, *waE2E.Message) *waE2E.Message
	BuildRevoke(types.JID, types.JID, types.MessageID) *waE2E.Message
	BuildReaction(types.JID, types.JID, types.MessageID, string) *waE2E.Message
	BuildPollVote(context.Context, *types.MessageInfo, []string) (*waE2E.Message, error)
	BuildUnavailableMessageRequest(types.JID, types.JID, string) *waE2E.Message
	SendPeerMessage(context.Context, *waE2E.Message) (whatsmeow.SendResponse, error)
	MarkRead(context.Context, []types.MessageID, time.Time, types.JID, types.JID, ...types.ReceiptType) error
	SendProtocolMessageReceipt(context.Context, types.MessageID, types.ReceiptType) error
	SetDisappearingTimer(context.Context, types.JID, time.Duration, time.Time) error
}

var _ actionClient = (*whatsmeow.Client)(nil)

func (a *WhatsMeowAdapter) EditMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageEditPayload,
	providerMessageID string,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" || strings.TrimSpace(payload.Text) == "" {
		return errors.New("edit target and text are required")
	}
	message := client.BuildEdit(
		chat, types.MessageID(payload.TargetMessageID),
		&waE2E.Message{Conversation: proto.String(payload.Text)},
	)
	return sendAction(ctx, client, chat, message, providerMessageID)
}

func (a *WhatsMeowAdapter) RevokeMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageTargetPayload,
	providerMessageID string,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	sender, err := actionSender(payload.Sender)
	if err != nil {
		return err
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" {
		return errors.New("revoke target is required")
	}
	return sendAction(
		ctx, client, chat,
		client.BuildRevoke(chat, sender, types.MessageID(payload.TargetMessageID)),
		providerMessageID,
	)
}

func (a *WhatsMeowAdapter) ReactMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageReactionPayload,
	providerMessageID string,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	sender, err := actionSender(payload.Sender)
	if err != nil {
		return err
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" || len(payload.Emoji) > 32 {
		return errors.New("invalid reaction target or emoji")
	}
	return sendAction(
		ctx, client, chat,
		client.BuildReaction(chat, sender, types.MessageID(payload.TargetMessageID), payload.Emoji),
		providerMessageID,
	)
}

func (a *WhatsMeowAdapter) VotePoll(
	ctx context.Context,
	sessionID string,
	payload domain.PollVotePayload,
	providerMessageID string,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	sender, err := actionSender(payload.Sender)
	if err != nil {
		return err
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" || len(payload.OptionNames) == 0 ||
		len(payload.OptionNames) > maxPollOptions {
		return errors.New("invalid poll vote")
	}
	info := &types.MessageInfo{
		MessageSource: types.MessageSource{
			Chat: chat, Sender: sender, IsFromMe: sender.IsEmpty(),
		},
		ID: types.MessageID(payload.TargetMessageID),
	}
	message, err := client.BuildPollVote(ctx, info, payload.OptionNames)
	if err != nil {
		return err
	}
	return sendAction(ctx, client, chat, message, providerMessageID)
}

func (a *WhatsMeowAdapter) MarkMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageMarkPayload,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	if len(payload.MessageIDs) == 0 || len(payload.MessageIDs) > 100 {
		return errors.New("mark requires 1 to 100 message IDs")
	}
	sender, err := actionSender(payload.Sender)
	if err != nil {
		return err
	}
	ids := make([]types.MessageID, len(payload.MessageIDs))
	for index, id := range payload.MessageIDs {
		if strings.TrimSpace(id) == "" {
			return errors.New("message ID is empty")
		}
		ids[index] = types.MessageID(id)
	}
	if payload.Protocol {
		if len(ids) != 1 {
			return errors.New("protocol receipt requires exactly one message ID")
		}
		switch strings.ToUpper(strings.TrimSpace(payload.Receipt)) {
		case "PEER":
			return client.SendProtocolMessageReceipt(ctx, ids[0], types.ReceiptTypePeerMsg)
		case "HISTORY_SYNC":
			return client.SendProtocolMessageReceipt(ctx, ids[0], types.ReceiptTypeHistorySync)
		default:
			return errors.New("unsupported protocol receipt")
		}
	}
	timestamp := time.Now()
	if payload.Timestamp > 0 {
		timestamp = time.Unix(payload.Timestamp, 0)
	}
	switch strings.ToUpper(strings.TrimSpace(payload.Receipt)) {
	case "READ":
		return client.MarkRead(ctx, ids, timestamp, chat, sender)
	case "PLAYED":
		return client.MarkRead(ctx, ids, timestamp, chat, sender, types.ReceiptTypePlayed)
	default:
		return errors.New("receipt must be READ or PLAYED")
	}
}

func (a *WhatsMeowAdapter) SetChatDisappearing(
	ctx context.Context,
	sessionID string,
	payload domain.DisappearingPayload,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	timer := time.Duration(payload.TimerSeconds) * time.Second
	switch timer {
	case whatsmeow.DisappearingTimerOff, whatsmeow.DisappearingTimer24Hours,
		whatsmeow.DisappearingTimer7Days, whatsmeow.DisappearingTimer90Days:
		return client.SetDisappearingTimer(ctx, chat, timer, time.Now())
	default:
		return errors.New("unsupported disappearing timer")
	}
}

func (a *WhatsMeowAdapter) RequestUnavailableMessage(
	ctx context.Context,
	sessionID string,
	payload domain.MessageTargetPayload,
) error {
	client, chat, err := a.readyActionClient(sessionID, payload.To)
	if err != nil {
		return err
	}
	sender, err := actionSender(payload.Sender)
	if err != nil {
		return err
	}
	if strings.TrimSpace(payload.TargetMessageID) == "" {
		return errors.New("unavailable message target is required")
	}
	_, err = client.SendPeerMessage(
		ctx, client.BuildUnavailableMessageRequest(chat, sender, payload.TargetMessageID),
	)
	return err
}

func (a *WhatsMeowAdapter) readyActionClient(sessionID, address string) (actionClient, types.JID, error) {
	client, chat, err := a.readyRecipient(sessionID, address)
	if err != nil {
		return nil, types.JID{}, err
	}
	action, ok := client.(actionClient)
	if !ok {
		return nil, types.JID{}, errors.New("WhatsApp client does not support message actions")
	}
	return action, chat, nil
}

func actionSender(value string) (types.JID, error) {
	if strings.TrimSpace(value) == "" {
		return types.EmptyJID, nil
	}
	return parseTypedDirectJID(value)
}

func sendAction(
	ctx context.Context,
	client actionClient,
	chat types.JID,
	message *waE2E.Message,
	providerMessageID string,
) error {
	if strings.TrimSpace(providerMessageID) == "" {
		return errors.New("provider message ID is required")
	}
	_, err := client.SendMessage(
		ctx, chat, message,
		whatsmeow.SendRequestExtra{ID: types.MessageID(providerMessageID)},
	)
	return err
}
