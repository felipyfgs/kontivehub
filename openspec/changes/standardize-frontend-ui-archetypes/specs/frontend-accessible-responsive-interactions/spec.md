## ADDED Requirements

### Requirement: Master-detail collections expose a complete keyboard model
Interactive master-detail collections SHALL expose semantic collection and option state, one predictable tab entry, arrow-key navigation, Home/End navigation, selection state, and focus restoration when a detail overlay closes.

#### Scenario: Keyboard user enters a mailbox list
- **WHEN** focus enters the mailbox collection
- **THEN** the selected item or first available item receives focus and is announced with its selected state

#### Scenario: Keyboard user moves through items
- **WHEN** the user presses ArrowUp, ArrowDown, Home, or End
- **THEN** focus moves to the expected available item without trapping the user or losing virtualized position metadata

#### Scenario: Mobile detail closes
- **WHEN** a selected record is shown in a slideover and the slideover closes
- **THEN** focus returns to the control that opened that record

### Requirement: Common lists use native actionable controls
Collections that do not implement listbox selection SHALL use list/listitem semantics with a native button or link for each row and SHALL expose current, busy, disabled, and selected state on the actionable control when applicable.

#### Scenario: Conversation row is current
- **WHEN** a conversation is the active detail record
- **THEN** its row action exposes the current state to assistive technology without making a non-interactive wrapper focusable

### Requirement: Motion preserves feedback under reduced-motion preference
Continuous loaders and transitions SHALL provide an intentional reduced-motion alternative that preserves state change, hierarchy, and completion feedback.

#### Scenario: Reduced motion is enabled
- **WHEN** `prefers-reduced-motion: reduce` is active during loading or a state transition
- **THEN** continuous spin, pulse, or decorative movement stops while text, icon, status semantics, and final state remain perceivable

### Requirement: Controls and text remain operable on touch devices
At viewports below `md`, actionable controls SHALL provide at least a 44×44 CSS-pixel touch target, visible focus, and a non-color-only label or accessible name. Visible operational text SHALL use at least the 12 px label token unless a verified non-interactive exception preserves legibility and contrast.

#### Scenario: User operates the calendar on mobile
- **WHEN** the calendar is rendered at a 390 px viewport
- **THEN** task and navigation controls meet the touch target contract without overlapping or truncating required actions

#### Scenario: Compact operational metadata is rendered
- **WHEN** a component displays message, KPI, process, or timeline metadata
- **THEN** required content remains legible at text zoom and does not depend on sub-12 px typography

### Requirement: Dense data transforms below the medium breakpoint
Administrative tables and tabular modals SHALL transform below `md` into cards, summaries with expandable details, or a master-detail/slideover composition that preserves identity, state, important values, and actions.

#### Scenario: Platform administrator opens a dense matrix on mobile
- **WHEN** a `platform_admin` opens fiscal module controls below `md`
- **THEN** each module remains readable and actionable without requiring horizontal page or table scrolling

#### Scenario: User opens a tabular history modal on mobile
- **WHEN** a history view would otherwise require a fixed wide table
- **THEN** the modal presents a responsive summary/detail composition with the same factual fields and actions

#### Scenario: Intrinsically bidimensional read-only content remains tabular
- **WHEN** content cannot be meaningfully linearized and has no hidden actions
- **THEN** any horizontal region is explicitly named, keyboard reachable when needed, and accompanied by a readable narrow-screen summary

### Requirement: Forms, media, and async feedback remain accessible
Visible controls SHALL have programmatic labels and error association; informative media SHALL have appropriate alternative text; asynchronous regions SHALL expose busy, status, error, and retry semantics without relying only on color or animation.

#### Scenario: Form validation fails
- **WHEN** a submitted field is invalid
- **THEN** its label, error message, invalid state, and focus destination are programmatically associated

#### Scenario: Collection loads without existing data
- **WHEN** an initially empty collection is loading
- **THEN** assistive technology receives a status/busy announcement and the final empty, error, or populated state replaces it coherently

### Requirement: Responsive and keyboard behavior is regression tested
Representative routes for every archetype SHALL be tested at mobile, tablet, and desktop widths with keyboard, focus, loading, error, empty, and reduced-motion scenarios.

#### Scenario: Frontend acceptance suite runs
- **WHEN** the change reaches final validation
- **THEN** `/`, `/clients`, `/monitoring/mailbox`, `/conta`, authentication, and dense admin surfaces pass targeted tests at 390, 768, and 1440 px or the nearest deterministic project viewports
