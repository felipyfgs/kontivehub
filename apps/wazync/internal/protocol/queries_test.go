package protocol

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/domain"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/types"
)

type fakeQueryClient struct {
	fakeClient
	sawDeadline          bool
	deviceQueries        int
	privacyFetch         int
	profilePictureParams *whatsmeow.GetProfilePictureParams
	profilePictureNil    bool
	profilePictureErr    error
	profilePictureWait   bool
}

type blockingProfilePictureClient struct {
	*fakeQueryClient
	started chan<- struct{}
	release <-chan struct{}
}

func (c *blockingProfilePictureClient) GetProfilePictureInfo(
	ctx context.Context,
	_ types.JID,
	_ *whatsmeow.GetProfilePictureParams,
) (*types.ProfilePictureInfo, error) {
	c.started <- struct{}{}
	select {
	case <-c.release:
		return nil, nil
	case <-ctx.Done():
		return nil, ctx.Err()
	}
}

type contactProfileQueryResolver struct {
	actionResolver
	contacts map[string]types.ContactInfo
	calls    int
	err      error
}

func (r *contactProfileQueryResolver) LookupContact(
	_ context.Context,
	_ string,
	jid types.JID,
) (types.ContactInfo, error) {
	r.calls++
	if r.err != nil {
		return types.ContactInfo{}, r.err
	}
	return r.contacts[jid.String()], nil
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
	ctx context.Context,
	_ types.JID,
	params *whatsmeow.GetProfilePictureParams,
) (*types.ProfilePictureInfo, error) {
	_, c.sawDeadline = ctx.Deadline()
	if params != nil {
		copyParams := *params
		c.profilePictureParams = &copyParams
	}
	if c.profilePictureErr != nil {
		return nil, c.profilePictureErr
	}
	if c.profilePictureWait {
		<-ctx.Done()
		return nil, ctx.Err()
	}
	if c.profilePictureNil {
		return nil, nil
	}
	return &types.ProfilePictureInfo{
		ID: "picture-safe-0001", URL: "https://pps.whatsapp.invalid/avatar", Type: "preview",
		DirectPath: "/sensitive/direct/path", Hash: []byte("sensitive-hash"),
	}, nil
}

func TestQueryTimeoutKeepsProfilePicturesSeparate(t *testing.T) {
	t.Parallel()

	if got := queryTimeout(domain.QueryProfilePicture); got != 80*time.Second {
		t.Fatalf("profile picture timeout = %s, want 80s", got)
	}
	for _, queryType := range []domain.QueryType{
		domain.QueryIsOnWhatsApp,
		domain.QueryUserInfo,
		domain.QueryContactProfiles,
		domain.QueryBusinessProfile,
		domain.QueryContactQRLink,
		domain.QueryResolveContactQR,
		domain.QueryResolveBusinessLink,
		domain.QueryBlocklist,
		domain.QueryPrivacySettings,
	} {
		if got := queryTimeout(queryType); got != 15*time.Second {
			t.Fatalf("%s timeout = %s, want 15s", queryType, got)
		}
	}
}

func TestProfilePictureQueryInFlightMetricTracksConcurrency(t *testing.T) {
	t.Parallel()

	started := make(chan struct{}, 2)
	release := make(chan struct{})
	client := &blockingProfilePictureClient{
		fakeQueryClient: &fakeQueryClient{fakeClient: fakeClient{connected: true}},
		started:         started,
		release:         release,
	}
	adapter := NewWhatsMeowAdapter(actionResolver{client: client})
	payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234", Preview: true})
	errors := make(chan error, 2)
	for index := range 2 {
		go func() {
			_, err := adapter.Execute(t.Context(), domain.Query{
				ContractVersion: "v1",
				QueryID:         "query-picture-concurrent-" + fmt.Sprint(index),
				SessionID:       "session-query-concurrent",
				Type:            domain.QueryProfilePicture,
				Payload:         payload,
			})
			errors <- err
		}()
	}
	<-started
	<-started
	if got := adapter.ProfilePictureQueriesInFlight(); got != 2 {
		t.Fatalf("profile picture queries in flight = %d, want 2", got)
	}
	close(release)
	for range 2 {
		if err := <-errors; err != nil {
			t.Fatalf("execute concurrent profile picture query: %v", err)
		}
	}
	if got := adapter.ProfilePictureQueriesInFlight(); got != 0 {
		t.Fatalf("profile picture queries in flight after completion = %d, want 0", got)
	}
}

func TestQueryExecutorProfilePictureUsesPreviewAndPreservesNilOrError(t *testing.T) {
	t.Parallel()

	t.Run("preview result", func(t *testing.T) {
		client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
		payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{
			User: "+5511999991234", Preview: true,
		})
		result, err := NewWhatsMeowAdapter(actionResolver{client: client}).Execute(t.Context(), domain.Query{
			ContractVersion: "v1", QueryID: "query-picture-preview", SessionID: "session-query-0001",
			Type: domain.QueryProfilePicture, Payload: payload,
		})
		if err != nil {
			t.Fatalf("execute picture query: %v", err)
		}
		if !client.sawDeadline || client.profilePictureParams == nil || !client.profilePictureParams.Preview {
			t.Fatalf("profile picture query lost deadline/preview: deadline=%v params=%+v", client.sawDeadline, client.profilePictureParams)
		}
		encoded, _ := json.Marshal(result)
		for _, forbidden := range []string{"direct", "hash", "@s.whatsapp.net", "@lid"} {
			if strings.Contains(strings.ToLower(string(encoded)), forbidden) {
				t.Fatalf("profile picture result leaked %q: %s", forbidden, encoded)
			}
		}
	})

	t.Run("nil is an explicit unavailable result", func(t *testing.T) {
		client := &fakeQueryClient{fakeClient: fakeClient{connected: true}, profilePictureNil: true}
		payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234", Preview: true})
		result, err := NewWhatsMeowAdapter(actionResolver{client: client}).Execute(t.Context(), domain.Query{
			ContractVersion: "v1", QueryID: "query-picture-nil", SessionID: "session-query-0001",
			Type: domain.QueryProfilePicture, Payload: payload,
		})
		if err != nil {
			t.Fatalf("execute nil picture query: %v", err)
		}
		object, ok := result.(map[string]any)
		if !ok || object["profile_picture"] != nil {
			t.Fatalf("nil profile picture changed contract: %#v", result)
		}
	})

	t.Run("provider error remains an error", func(t *testing.T) {
		client := &fakeQueryClient{
			fakeClient: fakeClient{connected: true}, profilePictureErr: errors.New("privacy unavailable"),
		}
		payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234", Preview: true})
		if _, err := NewWhatsMeowAdapter(actionResolver{client: client}).Execute(t.Context(), domain.Query{
			ContractVersion: "v1", QueryID: "query-picture-error", SessionID: "session-query-0001",
			Type: domain.QueryProfilePicture, Payload: payload,
		}); err == nil {
			t.Fatal("profile picture provider error was converted into permissive fallback")
		}
	})

	for _, testCase := range []struct {
		name     string
		provider error
		expected error
	}{
		{
			name:     "privacy restriction is classified without provider detail",
			provider: fmt.Errorf("provider wrapper: %w", whatsmeow.ErrProfilePictureUnauthorized),
			expected: domain.ErrProfilePictureHidden,
		},
		{
			name:     "missing picture is classified without provider detail",
			provider: fmt.Errorf("provider wrapper: %w", whatsmeow.ErrProfilePictureNotSet),
			expected: domain.ErrProfilePictureNotSet,
		},
	} {
		t.Run(testCase.name, func(t *testing.T) {
			client := &fakeQueryClient{
				fakeClient: fakeClient{connected: true}, profilePictureErr: testCase.provider,
			}
			payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234", Preview: true})
			_, err := NewWhatsMeowAdapter(actionResolver{client: client}).Execute(t.Context(), domain.Query{
				ContractVersion: "v1", QueryID: "query-picture-unavailable", SessionID: "session-query-0001",
				Type: domain.QueryProfilePicture, Payload: payload,
			})
			if !errors.Is(err, testCase.expected) {
				t.Fatalf("unexpected classified error: %v", err)
			}
		})
	}

	t.Run("caller deadline cancels a blocked provider", func(t *testing.T) {
		client := &fakeQueryClient{
			fakeClient: fakeClient{connected: true}, profilePictureWait: true,
		}
		payload, _ := json.Marshal(domain.ProfilePictureQueryPayload{User: "+5511999991234", Preview: true})
		ctx, cancel := context.WithTimeout(t.Context(), 20*time.Millisecond)
		defer cancel()
		_, err := NewWhatsMeowAdapter(actionResolver{client: client}).Execute(ctx, domain.Query{
			ContractVersion: "v1", QueryID: "query-picture-timeout", SessionID: "session-query-0001",
			Type: domain.QueryProfilePicture, Payload: payload,
		})
		if !errors.Is(err, context.DeadlineExceeded) {
			t.Fatalf("blocked profile query did not preserve deadline: %v", err)
		}
	})
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

func TestQueryExecutorReadsOnlyRequestedLocalContactProfiles(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	first := types.NewJID("5511999991234", types.DefaultUserServer)
	resolver := &contactProfileQueryResolver{
		actionResolver: actionResolver{client: client},
		contacts: map[string]types.ContactInfo{
			first.String(): {
				Found: true, FirstName: "Maria", FullName: "Maria da Silva",
				PushName: "Maria S.", BusinessName: "Empresa da Maria",
			},
		},
	}
	adapter := NewWhatsMeowAdapter(resolver)
	payload, _ := json.Marshal(domain.UsersQueryPayload{Users: []string{"+5511999991234", "+5511988887777"}})

	result, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-contact-profiles-0001", SessionID: "session-query-0001",
		Type: domain.QueryContactProfiles, Payload: payload,
	})
	if err != nil {
		t.Fatalf("execute contact profiles query: %v", err)
	}
	if resolver.calls != 2 {
		t.Fatalf("must look up exactly each requested identity once, calls=%d", resolver.calls)
	}
	encoded, _ := json.Marshal(result)
	var decoded struct {
		Profiles []contactProfileResult `json:"profiles"`
	}
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("decode profiles: %v", err)
	}
	if len(decoded.Profiles) != 2 || !decoded.Profiles[0].Found || decoded.Profiles[1].Found {
		t.Fatalf("must preserve one found/not-found entry per requested identity: %s", encoded)
	}
	profile := decoded.Profiles[0]
	if profile.User != "+5511999991234" || profile.AddressBookFirstName != "Maria" ||
		profile.AddressBookFullName != "Maria da Silva" || profile.PushName != "Maria S." ||
		profile.BusinessName != "Empresa da Maria" {
		t.Fatalf("separate local contact fields changed: %+v", profile)
	}
	for _, forbidden := range []string{"verified", "address_book_name", "jid", "device", "thumbnail"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("contact profile query leaked protocol field %q: %s", forbidden, encoded)
		}
	}
}

func TestQueryExecutorContactProfilesAcceptsExactlyOneHundredLocalLookups(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	resolver := &contactProfileQueryResolver{
		actionResolver: actionResolver{client: client}, contacts: map[string]types.ContactInfo{},
	}
	adapter := NewWhatsMeowAdapter(resolver)
	users := make([]string, maxQueryUsers)
	for index := range users {
		users[index] = fmt.Sprintf("+55119999%04d", index)
	}
	payload, _ := json.Marshal(domain.UsersQueryPayload{Users: users})

	result, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-contact-profiles-100", SessionID: "session-query-0001",
		Type: domain.QueryContactProfiles, Payload: payload,
	})
	if err != nil {
		t.Fatalf("execute exact-limit contact profiles query: %v", err)
	}
	if resolver.calls != maxQueryUsers {
		t.Fatalf("must consult every requested local contact exactly once: got=%d want=%d", resolver.calls, maxQueryUsers)
	}
	encoded, _ := json.Marshal(result)
	var decoded struct {
		Profiles []contactProfileResult `json:"profiles"`
	}
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("decode profiles: %v", err)
	}
	if len(decoded.Profiles) != maxQueryUsers {
		t.Fatalf("exact-limit query returned %d profiles, want %d", len(decoded.Profiles), maxQueryUsers)
	}
}

func TestQueryExecutorContactProfilesRejectsOversizedBatchBeforeLocalStore(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	resolver := &contactProfileQueryResolver{
		actionResolver: actionResolver{client: client}, contacts: map[string]types.ContactInfo{},
	}
	adapter := NewWhatsMeowAdapter(resolver)
	users := make([]string, maxQueryUsers+1)
	for index := range users {
		users[index] = fmt.Sprintf("+55119888%04d", index)
	}
	payload, _ := json.Marshal(domain.UsersQueryPayload{Users: users})

	if _, err := adapter.Execute(t.Context(), domain.Query{
		ContractVersion: "v1", QueryID: "query-contact-profiles-101", SessionID: "session-query-0001",
		Type: domain.QueryContactProfiles, Payload: payload,
	}); err == nil {
		t.Fatal("oversized contact profile batch was accepted")
	}
	if resolver.calls != 0 {
		t.Fatalf("oversized batch must fail before local store access, calls=%d", resolver.calls)
	}
}

func TestQueryExecutorContactProfilesTreatsMissingAndStoreErrorsAsNoEgress(t *testing.T) {
	t.Parallel()
	client := &fakeQueryClient{fakeClient: fakeClient{connected: true}}
	payload, _ := json.Marshal(domain.UsersQueryPayload{Users: []string{"+5511999991234"}})

	t.Run("not found", func(t *testing.T) {
		resolver := &contactProfileQueryResolver{
			actionResolver: actionResolver{client: client}, contacts: map[string]types.ContactInfo{},
		}
		result, err := NewWhatsMeowAdapter(resolver).Execute(t.Context(), domain.Query{
			ContractVersion: "v1", QueryID: "query-contact-profiles-missing", SessionID: "session-query-0001",
			Type: domain.QueryContactProfiles, Payload: payload,
		})
		if err != nil {
			t.Fatalf("missing local contact must be represented, not fetched: %v", err)
		}
		encoded, _ := json.Marshal(result)
		if strings.Contains(string(encoded), "address_book_") || strings.Contains(string(encoded), "push_name") {
			t.Fatalf("missing local contact must not synthesize profile fields: %s", encoded)
		}
	})

	t.Run("store error", func(t *testing.T) {
		resolver := &contactProfileQueryResolver{
			actionResolver: actionResolver{client: client}, contacts: map[string]types.ContactInfo{}, err: errors.New("store unavailable"),
		}
		if _, err := NewWhatsMeowAdapter(resolver).Execute(t.Context(), domain.Query{
			ContractVersion: "v1", QueryID: "query-contact-profiles-error", SessionID: "session-query-0001",
			Type: domain.QueryContactProfiles, Payload: payload,
		}); err == nil {
			t.Fatal("local store error must reach the caller without fallback egress")
		}
		if resolver.calls != 1 {
			t.Fatalf("unexpected local store attempts: %d", resolver.calls)
		}
	})
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
		{"contact profiles", domain.QueryContactProfiles, userBatch, "profiles"},
		{"business profile", domain.QueryBusinessProfile, userBatch, "business_profiles"},
		{"profile picture", domain.QueryProfilePicture, user, "profile_picture"},
		{"contact qr", domain.QueryContactQRLink, qr, "contact_qr_link"},
		{"contact resolve", domain.QueryResolveContactQR, contactLink, "contact"},
		{"business resolve", domain.QueryResolveBusinessLink, businessLink, "business"},
		{"blocklist", domain.QueryBlocklist, json.RawMessage(`{}`), "blocked_users"},
		{"privacy", domain.QueryPrivacySettings, json.RawMessage(`{}`), "settings"},
	}
	for index, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			testAdapter := adapter
			if test.query == domain.QueryContactProfiles {
				testAdapter = NewWhatsMeowAdapter(&contactProfileQueryResolver{
					actionResolver: actionResolver{client: client}, contacts: map[string]types.ContactInfo{},
				})
			}
			result, err := testAdapter.Execute(t.Context(), domain.Query{
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
