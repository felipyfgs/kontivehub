package protocol

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"testing"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

type fakeQueryClient struct {
	fakeClient
	sawDeadline   bool
	deviceQueries int
	privacyFetch  int
}

func (c *fakeQueryClient) IsOnWhatsApp(
	ctx context.Context,
	phones []string,
) ([]types.IsOnWhatsAppResponse, error) {
	_, c.sawDeadline = ctx.Deadline()
	return []types.IsOnWhatsAppResponse{{
		Query: phones[0], JID: types.NewJID(phones[0], types.DefaultUserServer),
		PhoneNumber: types.NewJID(phones[0], types.DefaultUserServer), IsIn: true,
	}}, nil
}

func (c *fakeQueryClient) GetUserInfo(
	ctx context.Context,
	jids []types.JID,
) (map[types.JID]types.UserInfo, error) {
	_, c.sawDeadline = ctx.Deadline()
	return map[types.JID]types.UserInfo{
		jids[0]: {
			Status: "Disponível", PictureID: "picture-safe-0001",
			Devices: []types.JID{types.NewADJID(jids[0].User, 1, 2)},
			LID:     types.NewJID("987654321", types.HiddenUserServer),
		},
	}, nil
}

func (c *fakeQueryClient) GetBusinessProfile(
	_ context.Context,
	jid types.JID,
) (*types.BusinessProfile, error) {
	return &types.BusinessProfile{
		JID: jid, Address: "Rua segura", Email: "contato@example.test",
		Categories: []types.Category{{ID: "1", Name: "Contabilidade"}},
	}, nil
}

func (c *fakeQueryClient) GetProfilePictureInfo(
	_ context.Context,
	_ types.JID,
	_ *whatsmeow.GetProfilePictureParams,
) (*types.ProfilePictureInfo, error) {
	return &types.ProfilePictureInfo{
		ID: "picture-safe-0001", URL: "https://pps.whatsapp.invalid/avatar", Type: "preview",
		DirectPath: "/sensitive/direct/path", Hash: []byte("sensitive-hash"),
	}, nil
}

func (c *fakeQueryClient) GetContactQRLink(context.Context, bool) (string, error) {
	return "https://wa.me/qr/SAFE", nil
}

func (c *fakeQueryClient) ResolveContactQRLink(
	context.Context,
	string,
) (*types.ContactQRLinkTarget, error) {
	return &types.ContactQRLinkTarget{
		JID: types.NewJID("5511999991234", types.DefaultUserServer), Type: "contact", PushName: "Cliente",
	}, nil
}

func (c *fakeQueryClient) ResolveBusinessMessageLink(
	context.Context,
	string,
) (*types.BusinessMessageLinkTarget, error) {
	return &types.BusinessMessageLinkTarget{
		JID: types.NewJID("5511999991234", types.DefaultUserServer), PushName: "Empresa",
		VerifiedName: "Empresa verificada", IsSigned: true, Message: "Olá",
	}, nil
}

func (c *fakeQueryClient) GetBlocklist(context.Context) (*types.Blocklist, error) {
	return &types.Blocklist{DHash: "must-not-leak", JIDs: []types.JID{
		types.NewJID("5511988887777", types.DefaultUserServer),
		types.NewJID("12345", types.GroupServer),
	}}, nil
}

func (c *fakeQueryClient) TryFetchPrivacySettings(
	context.Context,
	bool,
) (*types.PrivacySettings, error) {
	c.privacyFetch++
	settings := c.GetPrivacySettings(context.Background())
	return &settings, nil
}

func (c *fakeQueryClient) GetPrivacySettings(context.Context) types.PrivacySettings {
	return types.PrivacySettings{
		GroupAdd: types.PrivacySettingContacts, Status: types.PrivacySettingNone,
		LastSeen: types.PrivacySettingContacts, Profile: types.PrivacySettingContacts,
		ReadReceipts: types.PrivacySettingAll, Online: types.PrivacySettingMatchLastSeen,
		Messages: types.PrivacySettingContacts, Defense: types.PrivacySettingOnStandard,
		Stickers: types.PrivacySettingContacts,
	}
}

func (c *fakeQueryClient) GetUserDevices(context.Context, []types.JID) ([]types.JID, error) {
	c.deviceQueries++
	return nil, nil
}

func (c *fakeQueryClient) GetUserDevicesContext(context.Context, []types.JID) ([]types.JID, error) {
	c.deviceQueries++
	return nil, nil
}

func TestQueryExecutorSanitizesUserInfoAndNeverReturnsDeviceJIDs(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	payload, _ := json.Marshal(domain.UsersQueryPayload{Users: []string{"+5511999991234"}})

	result, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-user-info-0001", SessionID: "session-query-0001",
		Type: domain.QueryUserInfo, Payload: payload,
	})
	if err != nil {
		t.Fatalf("execute user info query: %v", err)
	}
	encoded, _ := json.Marshal(result)
	for _, forbidden := range []string{"device", "lid", "@s.whatsapp.net", "@lid"} {
		if strings.Contains(strings.ToLower(string(encoded)), forbidden) {
			t.Fatalf("sanitized query leaked %q: %s", forbidden, encoded)
		}
	}
	if !client.sawDeadline || client.deviceQueries != 0 || !strings.Contains(string(encoded), "+5511999991234") {
		t.Fatalf("query invariants changed: deadline=%v device_queries=%d result=%s",
			client.sawDeadline, client.deviceQueries, encoded)
	}
}

func TestQueryExecutorOmitsDirectMediaPathAndOutOfScopePrivacy(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	picturePayload, _ := json.Marshal(domain.ProfilePictureQueryPayload{
		User: "+5511999991234", Preview: true,
	})
	picture, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-picture-0001", SessionID: "session-query-0001",
		Type: domain.QueryProfilePicture, Payload: picturePayload,
	})
	if err != nil {
		t.Fatalf("execute picture query: %v", err)
	}
	encodedPicture, _ := json.Marshal(picture)
	if strings.Contains(string(encodedPicture), "direct") || strings.Contains(string(encodedPicture), "hash") {
		t.Fatalf("picture query leaked internal media fields: %s", encodedPicture)
	}

	privacy, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-privacy-0001", SessionID: "session-query-0001",
		Type: domain.QueryPrivacySettings, Payload: json.RawMessage(`{}`),
	})
	if err != nil {
		t.Fatalf("execute privacy query: %v", err)
	}
	encodedPrivacy, _ := json.Marshal(privacy)
	if strings.Contains(string(encodedPrivacy), "group") || strings.Contains(string(encodedPrivacy), "status") ||
		client.privacyFetch != 1 {
		t.Fatalf("privacy query exposed out-of-scope settings: %s fetches=%d", encodedPrivacy, client.privacyFetch)
	}
}

func TestQueryExecutorRejectsGroupAndUnboundedBatchBeforeProviderCall(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})

	groupPayload, _ := json.Marshal(domain.UsersQueryPayload{Users: []string{"12345@g.us"}})
	if _, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-group-0001", SessionID: "session-query-0001",
		Type: domain.QueryUserInfo, Payload: groupPayload,
	}); err == nil {
		t.Fatal("group target entered query executor")
	}
	users := make([]string, maxQueryUsers+1)
	for index := range users {
		users[index] = "+5511999991234"
	}
	batchPayload, _ := json.Marshal(domain.UsersQueryPayload{Users: users})
	if _, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-batch-0001", SessionID: "session-query-0001",
		Type: domain.QueryIsOnWhatsApp, Payload: batchPayload,
	}); err == nil {
		t.Fatal("unbounded user batch entered query executor")
	}
	if client.sawDeadline {
		t.Fatal("invalid query reached provider")
	}
}

func TestQueryExecutorMatchesStrictContractResultKeys(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	userBatch, _ := json.Marshal(domain.UsersQueryPayload{Users: []string{"+5511999991234"}})
	user, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234"})
	qr, _ := json.Marshal(domain.ContactQRQueryPayload{})
	contactLink, _ := json.Marshal(domain.LinkQueryPayload{Link: "https://wa.me/qr/SAFE"})
	businessLink, _ := json.Marshal(domain.LinkQueryPayload{Link: "https://wa.me/message/SAFE"})

	cases := []struct {
		name     string
		query    domain.QueryType
		payload  json.RawMessage
		expected string
	}{
		{"user check", domain.QueryIsOnWhatsApp, userBatch, "users"},
		{"user info", domain.QueryUserInfo, userBatch, "user_info"},
		{"business profile", domain.QueryBusinessProfile, userBatch, "business_profiles"},
		{"profile picture", domain.QueryProfilePicture, user, "profile_picture"},
		{"contact qr", domain.QueryContactQRLink, qr, "contact_qr_link"},
		{"contact resolve", domain.QueryResolveContactQR, contactLink, "contact"},
		{"business resolve", domain.QueryResolveBusinessURL, businessLink, "business"},
		{"blocklist", domain.QueryBlocklist, json.RawMessage(`{}`), "blocked_users"},
		{"privacy", domain.QueryPrivacySettings, json.RawMessage(`{}`), "settings"},
	}
	for index, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			result, err := adapter.Execute(t.Context(), domain.Query{
				ContractVersion: "v1", QueryID: domainQueryID(index), SessionID: "session-query-0001",
				Type: test.query, Payload: test.payload,
			})
			if err != nil {
				t.Fatalf("execute query: %v", err)
			}
			encoded, err := json.Marshal(result)
			if err != nil {
				t.Fatalf("marshal query result: %v", err)
			}
			var object map[string]any
			if err := json.Unmarshal(encoded, &object); err != nil {
				t.Fatalf("decode query result: %v", err)
			}
			if len(object) != 1 {
				t.Fatalf("query result must have one contract root key: %s", encoded)
			}
			if _, ok := object[test.expected]; !ok {
				t.Fatalf("query result missing %q: %s", test.expected, encoded)
			}
		})
	}
}

func domainQueryID(index int) string {
	return fmt.Sprintf("query-contract-%04d", index)
}
