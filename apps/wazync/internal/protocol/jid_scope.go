package protocol

import (
	"encoding/json"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
)

// ValidateCommandRecipientScope rejects out-of-scope addresses before a
// command can enter the durable ledger. Payload shape validation remains owned
// by domain.Command.ValidatePayload.
func ValidateCommandRecipientScope(command domain.Command) error {
	var recipients []string

	switch command.Type {
	case domain.CommandSendMessage:
		var payload domain.MessageSendPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
		if payload.ReplyTo != nil && payload.ReplyTo.Sender != "" {
			recipients = append(recipients, payload.ReplyTo.Sender)
		}
	case domain.CommandEditMessage:
		var payload domain.MessageEditPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandRevokeMessage, domain.CommandRequestUnavailableMessage:
		var payload domain.MessageTargetPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandReactMessage:
		var payload domain.MessageReactionPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandVotePoll:
		var payload domain.PollVotePayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandMarkMessage:
		var payload domain.MessageMarkPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
		if payload.Sender != "" {
			recipients = append(recipients, payload.Sender)
		}
	case domain.CommandRetryMedia:
		var payload domain.MediaRetryPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
		if payload.Sender != "" {
			recipients = append(recipients, payload.Sender)
		}
	case domain.CommandSubscribePresence:
		var payload domain.ContactPresencePayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandSetChatPresence:
		var payload domain.ChatPresencePayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandSetDisappearing:
		var payload domain.DisappearingPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandUpdateChatState:
		var payload domain.ChatStatePayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		if payload.To != "" {
			recipients = append(recipients, payload.To)
		}
	case domain.CommandUpdateBlocklist:
		var payload domain.BlocklistUpdatePayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
	case domain.CommandRequestHistorySync:
		var payload domain.HistorySyncPayload
		if err := json.Unmarshal(command.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.To)
		if payload.LastMessageFrom != "" {
			recipients = append(recipients, payload.LastMessageFrom)
		}
	}

	return validateRecipients(recipients)
}

// ValidateQueryRecipientScope rejects target addresses before executing a
// remote whatsmeow query. Queries without a user target have no address to
// validate at this layer.
func ValidateQueryRecipientScope(query domain.Query) error {
	var recipients []string

	switch query.Type {
	case domain.QueryIsOnWhatsApp, domain.QueryUserInfo, domain.QueryContactProfiles, domain.QueryBusinessProfile:
		var payload domain.UsersQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.Users...)
	case domain.QueryProfilePicture:
		var payload domain.ProfilePictureQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return ErrRecipientInvalid
		}
		recipients = append(recipients, payload.User)
	}

	return validateRecipients(recipients)
}

func validateRecipients(recipients []string) error {
	for _, recipient := range recipients {
		if _, err := NormalizeContractOneToOneAddress(recipient); err != nil {
			return err
		}
	}
	return nil
}
