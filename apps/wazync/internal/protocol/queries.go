package protocol

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

const (
	queryDeadline               = 15 * time.Second
	profilePictureQueryDeadline = 80 * time.Second
	maxQueryUsers               = 100
	maxLinkLength               = 512
)

type queryClient interface {
	WhatsMeowClient
	IsOnWhatsApp(context.Context, []string) ([]types.IsOnWhatsAppResponse, error)
	GetUserInfo(context.Context, []types.JID) (map[types.JID]types.UserInfo, error)
	GetBusinessProfile(context.Context, types.JID) (*types.BusinessProfile, error)
	GetProfilePictureInfo(context.Context, types.JID, *whatsmeow.GetProfilePictureParams) (*types.ProfilePictureInfo, error)
	GetContactQRLink(context.Context, bool) (string, error)
	ResolveContactQRLink(context.Context, string) (*types.ContactQRLinkTarget, error)
	ResolveBusinessMessageLink(context.Context, string) (*types.BusinessMessageLinkTarget, error)
	GetBlocklist(context.Context) (*types.Blocklist, error)
	TryFetchPrivacySettings(context.Context, bool) (*types.PrivacySettings, error)
	GetPrivacySettings(context.Context) types.PrivacySettings
	GetUserDevices(context.Context, []types.JID) ([]types.JID, error)
	GetUserDevicesContext(context.Context, []types.JID) ([]types.JID, error)
}

type contactProfileResolver interface {
	LookupContact(context.Context, string, types.JID) (types.ContactInfo, error)
}

var _ queryClient = (*whatsmeow.Client)(nil)

type userAvailabilityResult struct {
	Input        string `json:"input"`
	Exists       bool   `json:"exists"`
	User         string `json:"user,omitempty"`
	VerifiedName string `json:"verified_name,omitempty"`
}

type userInfoResult struct {
	User         string `json:"user"`
	Status       string `json:"status,omitempty"`
	VerifiedName string `json:"verified_name,omitempty"`
}

type contactProfileResult struct {
	User                 string `json:"user"`
	Found                bool   `json:"found"`
	AddressBookFirstName string `json:"address_book_first_name,omitempty"`
	AddressBookFullName  string `json:"address_book_full_name,omitempty"`
	PushName             string `json:"push_name,omitempty"`
	BusinessName         string `json:"business_name,omitempty"`
}

type pictureResult struct {
	User string `json:"user"`
	ID   string `json:"id,omitempty"`
	URL  string `json:"url"`
}

func (a *WhatsMeowAdapter) Execute(ctx context.Context, query domain.Query) (any, error) {
	if err := ValidateQueryRecipientScope(query); err != nil {
		return nil, err
	}
	client, err := a.readyQueryClient(query.SessionID)
	if err != nil {
		return nil, err
	}
	if query.Type == domain.QueryProfilePicture {
		a.profilePictureQueriesInFlight.Add(1)
		defer a.profilePictureQueriesInFlight.Add(-1)
	}
	ctx, cancel := context.WithTimeout(ctx, queryTimeout(query.Type))
	defer cancel()

	switch query.Type {
	case domain.QueryIsOnWhatsApp:
		var payload domain.UsersQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		addresses, err := normalizeQueryUsers(payload.Users)
		if err != nil {
			return nil, err
		}
		phones := make([]string, len(addresses))
		for index, address := range addresses {
			if address.Kind != AddressPN {
				return nil, errors.New("availability lookup requires phone-number addresses")
			}
			phones[index] = address.JID.User
		}
		response, err := client.IsOnWhatsApp(ctx, phones)
		if err != nil {
			return nil, err
		}
		result := make([]userAvailabilityResult, 0, len(response))
		for _, item := range response {
			user := ""
			if normalized, err := NormalizeOneToOneJID(item.PhoneNumber); err == nil {
				user = normalized.Normalized
			} else if normalized, err := NormalizeOneToOneJID(item.JID); err == nil {
				user = normalized.Normalized
			}
			result = append(result, userAvailabilityResult{
				Input: "+" + strings.TrimPrefix(item.Query, "+"), Exists: item.IsIn,
				User: user, VerifiedName: verifiedName(item.VerifiedName),
			})
		}
		return map[string]any{"users": result}, nil

	case domain.QueryUserInfo:
		var payload domain.UsersQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		addresses, err := normalizeQueryUsers(payload.Users)
		if err != nil {
			return nil, err
		}
		jids := make([]types.JID, len(addresses))
		for index, address := range addresses {
			jids[index] = address.JID
		}
		response, err := client.GetUserInfo(ctx, jids)
		if err != nil {
			return nil, err
		}
		result := make([]userInfoResult, 0, len(response))
		for jid, info := range response {
			normalized, err := NormalizeOneToOneJID(jid)
			if err != nil {
				continue
			}
			result = append(result, userInfoResult{
				User: normalized.Normalized, Status: info.Status,
				VerifiedName: verifiedName(info.VerifiedName),
			})
		}
		return map[string]any{"user_info": result}, nil

	case domain.QueryContactProfiles:
		var payload domain.UsersQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		addresses, err := normalizeQueryUsers(payload.Users)
		if err != nil {
			return nil, err
		}
		profiles, ok := a.clients.(contactProfileResolver)
		if !ok {
			return nil, errors.New("WhatsApp resolver does not support local contact profiles")
		}
		result := make([]contactProfileResult, 0, len(addresses))
		for _, address := range addresses {
			info, err := profiles.LookupContact(ctx, query.SessionID, address.JID)
			if err != nil {
				return nil, err
			}
			entry := contactProfileResult{
				User:                 address.Normalized,
				Found:                info.Found,
				AddressBookFirstName: boundedText(info.FirstName, 512),
				AddressBookFullName:  boundedText(info.FullName, 512),
				PushName:             boundedText(info.PushName, 512),
				BusinessName:         boundedText(info.BusinessName, 512),
			}
			result = append(result, entry)
		}
		return map[string]any{"profiles": result}, nil

	case domain.QueryBusinessProfile:
		var payload domain.UsersQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		addresses, err := normalizeQueryUsers(payload.Users)
		if err != nil {
			return nil, err
		}
		profiles := make([]map[string]any, 0, len(addresses))
		for _, address := range addresses {
			profile, err := client.GetBusinessProfile(ctx, address.JID)
			if err != nil {
				return nil, err
			}
			profiles = append(profiles, sanitizeBusinessProfile(address.Normalized, profile))
		}
		return map[string]any{"business_profiles": profiles}, nil

	case domain.QueryProfilePicture:
		var payload domain.ProfilePictureQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		address, err := NormalizeOneToOneAddress(payload.User)
		if err != nil {
			return nil, err
		}
		picture, err := client.GetProfilePictureInfo(ctx, address.JID, &whatsmeow.GetProfilePictureParams{
			Preview: payload.Preview,
		})
		if errors.Is(err, whatsmeow.ErrProfilePictureUnauthorized) {
			return nil, domain.ErrProfilePictureHidden
		}
		if errors.Is(err, whatsmeow.ErrProfilePictureNotSet) {
			return nil, domain.ErrProfilePictureNotSet
		}
		if err != nil {
			return nil, err
		}
		if picture == nil {
			return map[string]any{"profile_picture": nil}, nil
		}
		return map[string]any{"profile_picture": pictureResult{
			User: address.Normalized, ID: picture.ID, URL: picture.URL,
		}}, nil

	case domain.QueryContactQRLink:
		var payload domain.ContactQRQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		link, err := client.GetContactQRLink(ctx, payload.Revoke)
		if err != nil {
			return nil, err
		}
		return map[string]any{"contact_qr_link": map[string]string{"link": link}}, nil

	case domain.QueryResolveContactQR:
		var payload domain.LinkQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		if err := validateResolverLink(payload.Link, "contact"); err != nil {
			return nil, err
		}
		target, err := client.ResolveContactQRLink(ctx, payload.Link)
		if err != nil {
			return nil, err
		}
		address, err := NormalizeOneToOneJID(target.JID)
		if err != nil {
			return nil, err
		}
		return map[string]any{"contact": map[string]string{"user": address.Normalized}}, nil

	case domain.QueryResolveBusinessLink:
		var payload domain.LinkQueryPayload
		if err := json.Unmarshal(query.Payload, &payload); err != nil {
			return nil, err
		}
		if err := validateResolverLink(payload.Link, "business"); err != nil {
			return nil, err
		}
		target, err := client.ResolveBusinessMessageLink(ctx, payload.Link)
		if err != nil {
			return nil, err
		}
		address, err := NormalizeOneToOneJID(target.JID)
		if err != nil {
			return nil, err
		}
		return map[string]any{"business": map[string]string{
			"user": address.Normalized, "message": truncateString(target.Message, maxMessageTextBytes),
		}}, nil

	case domain.QueryBlocklist:
		blocklist, err := client.GetBlocklist(ctx)
		if err != nil {
			return nil, err
		}
		users := make([]string, 0, len(blocklist.JIDs))
		for _, jid := range blocklist.JIDs {
			if address, err := NormalizeOneToOneJID(jid); err == nil {
				users = append(users, address.Normalized)
			}
		}
		return map[string]any{"blocked_users": users}, nil

	case domain.QueryPrivacySettings:
		if _, err := client.TryFetchPrivacySettings(ctx, false); err != nil {
			return nil, err
		}
		settings := client.GetPrivacySettings(ctx)
		return sanitizePrivacySettings(settings), nil
	}
	return nil, errors.New("unsupported query type")
}

// ProfilePictureQueriesInFlight exposes only an aggregate gauge. Query, session,
// recipient and provider identifiers are intentionally absent.
func (a *WhatsMeowAdapter) ProfilePictureQueriesInFlight() int64 {
	return a.profilePictureQueriesInFlight.Load()
}

func queryTimeout(queryType domain.QueryType) time.Duration {
	if queryType == domain.QueryProfilePicture {
		return profilePictureQueryDeadline
	}
	return queryDeadline
}

func (a *WhatsMeowAdapter) readyQueryClient(sessionID string) (queryClient, error) {
	client, err := a.clients.Resolve(sessionID)
	if err != nil {
		return nil, err
	}
	if !client.IsConnected() {
		return nil, errors.New("WhatsApp session is not connected")
	}
	queries, ok := client.(queryClient)
	if !ok {
		return nil, errors.New("WhatsApp client does not support contact queries")
	}
	return queries, nil
}

func normalizeQueryUsers(users []string) ([]OneToOneAddress, error) {
	if len(users) == 0 || len(users) > maxQueryUsers {
		return nil, errors.New("query requires 1 to 100 users")
	}
	result := make([]OneToOneAddress, len(users))
	for index, user := range users {
		address, err := NormalizeOneToOneAddress(user)
		if err != nil {
			return nil, err
		}
		result[index] = address
	}
	return result, nil
}

func verifiedName(name *types.VerifiedName) string {
	if name == nil || name.Details == nil {
		return ""
	}
	return name.Details.GetVerifiedName()
}

func sanitizeBusinessProfile(address string, profile *types.BusinessProfile) map[string]any {
	if profile == nil {
		return map[string]any{"user": address}
	}
	category := ""
	if len(profile.Categories) > 0 {
		category = profile.Categories[0].Name
	}
	result := map[string]any{"user": address}
	for key, value := range map[string]string{
		"name": profile.ProfileOptions["name"], "description": profile.ProfileOptions["description"],
		"email": profile.Email, "website": profile.ProfileOptions["website"], "category": category,
	} {
		if strings.TrimSpace(value) != "" {
			result[key] = value
		}
	}
	return result
}

func sanitizePrivacySettings(settings types.PrivacySettings) map[string]any {
	return map[string]any{"settings": []map[string]string{
		{"name": "last", "value": string(settings.LastSeen)},
		{"name": "profile", "value": string(settings.Profile)},
		{"name": "readreceipts", "value": string(settings.ReadReceipts)},
		{"name": "online", "value": string(settings.Online)},
	}}
}

func validateResolverLink(link, kind string) error {
	link = strings.TrimSpace(link)
	if link == "" || len(link) > maxLinkLength || strings.ContainsAny(link, "\r\n\t ") {
		return errors.New("invalid WhatsApp resolver link")
	}
	lower := strings.ToLower(link)
	if strings.Contains(lower, "/channel/") || strings.Contains(lower, "newsletter") {
		return ErrRecipientScopeNotAllowed
	}
	if strings.Contains(lower, "http://") {
		return errors.New("insecure WhatsApp resolver link")
	}
	if strings.Contains(lower, "://") && !strings.HasPrefix(lower, "https://wa.me/") &&
		!strings.HasPrefix(lower, "https://api.whatsapp.com/") {
		return fmt.Errorf("unsupported %s link host", kind)
	}
	return nil
}

func truncateString(value string, maximum int) string {
	if len(value) <= maximum {
		return value
	}
	return value[:maximum]
}
