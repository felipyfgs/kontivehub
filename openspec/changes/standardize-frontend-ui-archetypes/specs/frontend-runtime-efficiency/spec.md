## ADDED Requirements

### Requirement: Unauthorized pages terminate before domain loading
Protected frontend routes SHALL resolve effective identity and permission before instantiating domain loaders, opening realtime resources, or issuing protected API requests, and SHALL terminate setup after redirecting an unauthorized user.

#### Scenario: User lacks communication permission
- **WHEN** the user navigates directly to a communication flow or quick-response route without `communication.view`
- **THEN** the route redirects to an authorized destination and issues no flow, catalog, or editor API request

#### Scenario: Tenant context is unresolved
- **WHEN** a tenant-owned route is entered without a valid effective tenant
- **THEN** the frontend fails closed and does not retain or render data from a previous tenant epoch

### Requirement: Identity and onboarding bootstrap requests are deduplicated
The SPA SHALL have one authoritative identity refresh per navigation/bootstrap event and SHALL single-flight equivalent concurrent requests. Guest onboarding availability SHALL be cached within the SPA session and invalidated after an action that can change installation state.

#### Scenario: Authenticated navigation completes
- **WHEN** an authenticated user moves between protected routes
- **THEN** the shell and page consumers share the middleware identity result without an additional mount-time `/me` request

#### Scenario: Multiple guest guards need onboarding state
- **WHEN** guest navigation causes concurrent checks of onboarding availability
- **THEN** the frontend sends one availability request and all guards observe the same result

### Requirement: Communication realtime is conditional and disposable
The Web application SHALL initialize Echo/Pusher only when communication is enabled, the user is authenticated, the effective identity can view communication, and tenant context is valid. It SHALL close channels and transport when any prerequisite is lost.

#### Scenario: User cannot view communication
- **WHEN** communication is globally enabled but the authenticated identity lacks permission
- **THEN** no communication websocket connection is opened

#### Scenario: Tenant or session changes
- **WHEN** the active tenant changes, permission is revoked, or the user logs out
- **THEN** existing communication subscriptions and transport are torn down before another context can subscribe

### Requirement: Heavy route capabilities load on demand
Code and dependencies used only by graphs, visual flow editing, drag-and-drop, or other heavy route capabilities SHALL remain outside unrelated initial route chunks when Nuxt build measurements show they can be isolated without breaking the installed APIs.

#### Scenario: User opens an unrelated route
- **WHEN** login, Home, clients, or account is loaded without entering a visual editor or chart surface
- **THEN** route-specific editor and chart modules are not fetched as part of that route's required application code

#### Scenario: Optimization candidate is evaluated
- **WHEN** an import, API facade, or `optimizeDeps` entry is proposed for change
- **THEN** the implementation records a reproducible baseline and accepts the change only when generated chunks or runtime requests improve without functional regression

### Requirement: Obsolete async work cannot update a new context
Long-running frontend requests, polling loops, and subscriptions SHALL be cancellable or epoch-guarded and SHALL release timers/listeners when their route, component, session, or tenant context ends.

#### Scenario: User changes tenant during a request
- **WHEN** an API response from the previous tenant completes after the effective tenant changes
- **THEN** the response is ignored and cannot replace state for the new tenant

#### Scenario: User leaves a polling surface
- **WHEN** the owning route or component is disposed
- **THEN** its polling timer, abortable request, listener, and subscription are released

### Requirement: Runtime optimizations preserve contracts and failure honesty
Runtime refactors SHALL preserve typed API call shapes, Sanctum cookie authentication, public API contracts, permission checks, and explicit error states, and SHALL NOT introduce synthetic fallback data.

#### Scenario: Lazy API client is initialized
- **WHEN** a domain API client is first requested
- **THEN** it exposes the same typed methods and error behavior expected by existing consumers

#### Scenario: Optimized request fails
- **WHEN** a lazily loaded module or deduplicated request fails
- **THEN** the owning surface shows its real error/unavailable state and does not reuse stale data from another session or tenant
