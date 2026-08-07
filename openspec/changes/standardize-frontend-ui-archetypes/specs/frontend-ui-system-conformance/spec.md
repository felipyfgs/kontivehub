## ADDED Requirements

### Requirement: Every visible surface follows a registered archetype
The Web application SHALL classify every visible route as global shell, analytical view, administrative list, master-detail, settings/form, or authentication, and SHALL preserve the corresponding Nuxt UI hierarchy, slots, states, and responsive behavior from the validated dashboard reference.

#### Scenario: Existing route is rendered
- **WHEN** any authenticated route in the parity matrix is rendered
- **THEN** its page and inherited shell match the registered archetype without introducing a parallel dashboard shell

#### Scenario: Route inventory changes
- **WHEN** a visible page is created, renamed, or removed
- **THEN** the parity matrix is updated in the same change with its route, bundle, and archetype

### Requirement: Shared Shell components preserve the full interaction contract
The Web application SHALL reuse Nuxt UI primitives and `Shell*` components only when the abstraction preserves hierarchy, slots, semantic tokens, loading/error/empty states, focus behavior, and responsive transformation.

#### Scenario: Administrative list is implemented
- **WHEN** a route presents a filterable or pageable administrative collection
- **THEN** it uses the canonical list toolbar, data table, mobile cards, empty/error feedback, and footer contracts instead of locally rebuilding them

#### Scenario: Existing Shell does not fit
- **WHEN** a surface requires a structurally different interaction than an existing `Shell*`
- **THEN** it uses the nearest validated archetype without widening a fidelity allowlist merely to accept new visual chrome

### Requirement: KontiveHub visual identity remains canonical
The Web application SHALL use green as primary, zinc as neutral, Public Sans, Lucide icons, and Nuxt UI semantic tokens, and SHALL NOT allow runtime mutation to arbitrary global primary or neutral palettes.

#### Scenario: User opens the account menu
- **WHEN** the user views or operates the user menu
- **THEN** no control can replace the canonical global primary or neutral palette

#### Scenario: Dark mode metadata is produced
- **WHEN** the application is rendered in dark mode or installed as a PWA
- **THEN** browser and manifest theme colors use the canonical dark canvas `#09090b`

#### Scenario: Product status color is rendered
- **WHEN** a component communicates success, information, warning, error, selection, or availability
- **THEN** it uses the corresponding semantic token rather than a raw palette color

### Requirement: Authentication follows the product visual system and positioning
Authentication surfaces SHALL use the canonical typography, tonal surfaces, borders, spacing, and radii without decorative gradients, large blur effects, fabricated claims, or unconfirmed “internal use” positioning.

#### Scenario: Guest opens an authentication route
- **WHEN** login, activation, first access, reset password, or onboarding is rendered
- **THEN** the surface remains visually consistent with KontiveHub and uses factual pt-BR copy

### Requirement: Home is a real-data analytical variation
The Home route SHALL preserve its operational blocks within the analytical panel hierarchy and SHALL render only metrics, filters, charts, tables, and states backed by real API contracts.

#### Scenario: Operational Home loads successfully
- **WHEN** the API returns current operational data
- **THEN** Home presents that data in a scan-first analytical composition with title, state, value, and action hierarchy

#### Scenario: Analytical data is unavailable
- **WHEN** no approved API contract supplies a period, chart, or table
- **THEN** Home does not synthesize or copy reference data to fill the missing visualization

### Requirement: Visible copy and feedback reflect real product state
All visible product copy SHALL be in pt-BR, SHALL use confirmed KontiveHub terminology, and SHALL distinguish loading, error, empty, unavailable, success, and denied states without presenting invented fallback data.

#### Scenario: API request fails
- **WHEN** a visible data request fails
- **THEN** the surface shows an explicit pt-BR error or unavailable state and does not display synthetic data as valid

#### Scenario: UI strings are validated
- **WHEN** frontend quality gates scan visible labels and messages
- **THEN** known English status or retry strings are rejected unless they are domain identifiers that must remain unchanged
