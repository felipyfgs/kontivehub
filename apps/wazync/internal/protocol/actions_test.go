package protocol

import (
	"context"
	"io"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

type actionResolver struct{ client WhatsMeowClient }

func (r actionResolver) Resolve(string) (WhatsMeowClient, error) { return r.client, nil }

type fakeActionClient struct {
	fakeClient
	builder         string
	peerSent        bool
	marked          types.ReceiptType
	timer           time.Duration
	target          types.MessageID
	targetSender    types.JID
	selectedOption  []string
	protocolReceipt types.ReceiptType
}

func (c *fakeActionClient) UploadReader(
	context.Context, io.Reader, io.ReadWriteSeeker, whatsmeow.MediaType,
) (whatsmeow.UploadResponse, error) {
	return whatsmeow.UploadResponse{}, nil
}

func (c *fakeActionClient) BuildPollCreation(string, []string, int) *waE2E.Message {
	return &waE2E.Message{}
}

func (c *fakeActionClient) BuildEdit(_ types.JID, id types.MessageID, content *waE2E.Message) *waE2E.Message {
	c.builder, c.target = "edit", id
	return &waE2E.Message{EditedMessage: &waE2E.FutureProofMessage{Message: content}}
}

func (c *fakeActionClient) BuildRevoke(_ types.JID, sender types.JID, id types.MessageID) *waE2E.Message {
	c.builder, c.target, c.targetSender = "revoke", id, sender
	return &waE2E.Message{ProtocolMessage: &waE2E.ProtocolMessage{}}
}

func (c *fakeActionClient) BuildReaction(_ types.JID, sender types.JID, id types.MessageID, emoji string) *waE2E.Message {
	c.builder, c.target, c.targetSender = "reaction:"+emoji, id, sender
	return &waE2E.Message{ReactionMessage: &waE2E.ReactionMessage{}}
}

func (c *fakeActionClient) BuildPollVote(
	_ context.Context,
	info *types.MessageInfo,
	options []string,
) (*waE2E.Message, error) {
	c.builder, c.target, c.targetSender = "poll-vote", info.ID, info.Sender
	c.selectedOption = append([]string(nil), options...)
	return &waE2E.Message{PollUpdateMessage: &waE2E.PollUpdateMessage{}}, nil
}

func (c *fakeActionClient) BuildUnavailableMessageRequest(
	_ types.JID,
	sender types.JID,
	id string,
) *waE2E.Message {
	c.builder, c.target, c.targetSender = "unavailable", types.MessageID(id), sender
	return &waE2E.Message{ProtocolMessage: &waE2E.ProtocolMessage{}}
}

func (c *fakeActionClient) SendPeerMessage(context.Context, *waE2E.Message) (whatsmeow.SendResponse, error) {
	c.peerSent = true
	return whatsmeow.SendResponse{}, nil
}

func (c *fakeActionClient) MarkRead(
	_ context.Context,
	ids []types.MessageID,
	_ time.Time,
	_, _ types.JID,
	receipt ...types.ReceiptType,
) error {
	c.builder, c.target = "mark", ids[0]
	c.marked = types.ReceiptTypeRead
	if len(receipt) == 1 {
		c.marked = receipt[0]
	}
	return nil
}

func (c *fakeActionClient) SendProtocolMessageReceipt(
	_ context.Context,
	id types.MessageID,
	receipt types.ReceiptType,
) error {
	c.builder, c.target, c.protocolReceipt = "protocol-receipt", id, receipt
	return nil
}

func (c *fakeActionClient) SetDisappearingTimer(
	_ context.Context,
	_ types.JID,
	timer time.Duration,
	_ time.Time,
) error {
	c.builder, c.timer = "disappearing", timer
	return nil
}

func TestActionAdapterUsesSupportedWhatsmeowBuildersForDirectChats(t *testing.T) {
	t.Parallel()
	client := &fakeActionClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	ctx := t.Context()
	const sessionID = "session-action-0001"
	const to = "+5511999991234"
	const target = "target-message-0001"

	if err := adapter.EditMessage(ctx, sessionID, domain.MessageEditPayload{
		MessageTargetPayload: domain.MessageTargetPayload{To: to, TargetMessageID: target}, Text: "editado",
	}, "action-edit-0001"); err != nil || client.builder != "edit" || client.extra.ID != "action-edit-0001" {
		t.Fatalf("edit action failed: builder=%q id=%q err=%v", client.builder, client.extra.ID, err)
	}
	if err := adapter.RevokeMessage(ctx, sessionID, domain.MessageTargetPayload{
		To: to, TargetMessageID: target,
	}, "action-revoke-0001"); err != nil || client.builder != "revoke" {
		t.Fatalf("revoke action failed: builder=%q err=%v", client.builder, err)
	}
	if err := adapter.ReactMessage(ctx, sessionID, domain.MessageReactionPayload{
		MessageTargetPayload: domain.MessageTargetPayload{To: to, TargetMessageID: target, Sender: to}, Emoji: "✅",
	}, "action-reaction-0001"); err != nil || client.builder != "reaction:✅" || client.targetSender.User != "5511999991234" {
		t.Fatalf("reaction action failed: builder=%q sender=%s err=%v", client.builder, client.targetSender, err)
	}
	if err := adapter.VotePoll(ctx, sessionID, domain.PollVotePayload{
		MessageTargetPayload: domain.MessageTargetPayload{To: to, TargetMessageID: target, Sender: to},
		OptionNames:          []string{"A"},
	}, "action-vote-0001"); err != nil || client.builder != "poll-vote" || len(client.selectedOption) != 1 {
		t.Fatalf("poll vote failed: builder=%q options=%v err=%v", client.builder, client.selectedOption, err)
	}
	if err := adapter.MarkMessage(ctx, sessionID, domain.MessageMarkPayload{
		To: to, MessageIDs: []string{target}, Receipt: "PLAYED",
	}); err != nil || client.marked != types.ReceiptTypePlayed {
		t.Fatalf("played receipt failed: receipt=%q err=%v", client.marked, err)
	}
	if err := adapter.MarkMessage(ctx, sessionID, domain.MessageMarkPayload{
		To: to, MessageIDs: []string{target}, Receipt: "HISTORY_SYNC", Protocol: true,
	}); err != nil || client.protocolReceipt != types.ReceiptTypeHistorySync {
		t.Fatalf("protocol receipt failed: receipt=%q err=%v", client.protocolReceipt, err)
	}
	if err := adapter.SetChatDisappearing(ctx, sessionID, domain.DisappearingPayload{
		To: to, TimerSeconds: uint32((24 * time.Hour).Seconds()),
	}); err != nil || client.timer != 24*time.Hour {
		t.Fatalf("disappearing timer failed: timer=%s err=%v", client.timer, err)
	}
	if err := adapter.RequestUnavailableMessage(ctx, sessionID, domain.MessageTargetPayload{
		To: to, TargetMessageID: target, Sender: to,
	}); err != nil || client.builder != "unavailable" || !client.peerSent {
		t.Fatalf("unavailable request failed: builder=%q peer=%v err=%v", client.builder, client.peerSent, err)
	}
}

func TestActionAdapterRejectsUnsupportedTimerAndNonDirectSender(t *testing.T) {
	t.Parallel()
	client := &fakeActionClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})

	if err := adapter.SetChatDisappearing(t.Context(), "session-action-0001", domain.DisappearingPayload{
		To: "+5511999991234", TimerSeconds: 123,
	}); err == nil {
		t.Fatal("unsupported disappearing timer was accepted")
	}
	if err := adapter.ReactMessage(t.Context(), "session-action-0001", domain.MessageReactionPayload{
		MessageTargetPayload: domain.MessageTargetPayload{
			To: "+5511999991234", TargetMessageID: "target-message-0001", Sender: "12345@g.us",
		}, Emoji: "✅",
	}, "action-invalid-0001"); err == nil {
		t.Fatal("group sender entered a direct message action")
	}
}
