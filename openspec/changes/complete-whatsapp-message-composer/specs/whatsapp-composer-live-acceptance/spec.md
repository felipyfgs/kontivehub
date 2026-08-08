## ADDED Requirements

### Requirement: Composer acceptance uses post-deploy sends
Acceptance SHALL establish database, JetStream and browser watermarks after deployment and SHALL use only composer actions and WhatsApp events occurring afterward.

#### Scenario: Old message already has the requested kind
- **WHEN** a matching message predates the composer watermark
- **THEN** it remains diagnostic evidence and cannot satisfy live composer acceptance

### Requirement: Every advertised family is proven end to end
For each capability advertised to the test inbox, evidence SHALL correlate the composer action, Laravel message or batch, attachment state, outbox command, Wazync result, API resource and DOM element with the same technical ID.

#### Scenario: Structured message is submitted
- **WHEN** the operator sends location, contacts, poll, event or interactive content
- **THEN** evidence shows the structured draft became the matching semantic API content and specific timeline card

#### Scenario: Media batch is submitted
- **WHEN** the operator sends multiple supported files from one preview
- **THEN** evidence shows stable batch ordering, one status per child, decodable media and no duplicate command/message

### Requirement: Composer interaction is verified visually
Playwright CLI verification SHALL cover desktop and mobile launchers, forms, destination context, expression picker, media preview, per-item lifecycle, validation, voice states, focus/keyboard behavior and resulting bubbles without exposing message bodies or contact values in the report.

#### Scenario: Voice note is recorded in desktop browser
- **WHEN** a controlled microphone fixture exercises record, pause, preview and send
- **THEN** the UI state transitions are visible, exactly one PTT message appears and all media tracks/object URLs are released

#### Scenario: Mobile attachment flow is used
- **WHEN** a mobile viewport opens a structured family, confirms it and sends
- **THEN** the sheet/dialog and composer remain usable without overflow and the new bubble is visible without a full page reload

#### Scenario: Composer is exercised under accessibility constraints
- **WHEN** Playwright uses keyboard-only interaction, reduced motion, a mobile safe area and 200 percent-equivalent zoom
- **THEN** grouped navigation, context, status, cancel and send controls remain perceivable and operable with focus restored after every temporary surface

#### Scenario: KontiveHub visual language is preserved
- **WHEN** desktop and mobile screenshots are reviewed against `DESIGN.md`
- **THEN** the composer uses the incumbent Nuxt UI hierarchy, neutral operational surfaces and semantic action color without copying the WhatsApp visual theme or creating a parallel shell

### Requirement: Unsupported capability is reported honestly
The acceptance report SHALL classify every requested family or variant as `observado ao vivo`, `fixture apenas`, `indisponível por capability` or `falhou` and SHALL NOT equate a rendered control or passing fixture with a successful real send.

#### Scenario: No GIF provider is configured
- **WHEN** remote GIF search is disabled by capability
- **THEN** acceptance records `indisponível por capability` and verifies the safe disabled state instead of claiming GIF support

#### Scenario: Protocol builder exists only in fixtures
- **WHEN** event, album, PTV or view-once cannot be produced with the connected account
- **THEN** the report labels it fixture-only and requests only the missing live action

### Requirement: Failure and recovery paths are observed
Acceptance SHALL exercise queue unavailability, unreadable outbound media, retry/idempotency and action projection so the composer never clears a draft or claims delivery prematurely.

#### Scenario: Initial command publish fails transiently
- **WHEN** JetStream cannot accept the first publish and later recovers
- **THEN** one idempotent command eventually succeeds, status never regresses and the composer retains or reconciles the draft visibly

#### Scenario: Media fetch fails terminally
- **WHEN** Wazync cannot fetch an outbound object after bounded retries
- **THEN** the message is classified failed, the operator can retry intentionally and the report does not count it as delivered

#### Scenario: One batch child fails after acceptance
- **WHEN** a post-watermark media batch reaches mixed child states
- **THEN** the DOM and API expose the same state per child and retrying the failed child does not duplicate children already accepted or delivered

### Requirement: Real user confirmation closes external delivery
For sends to a user-controlled test number, acceptance SHALL record recipient confirmation for families whose successful local status alone cannot prove correct external presentation.

#### Scenario: Test recipient reviews the matrix
- **WHEN** the system reports messages delivered to the authorized test number
- **THEN** the user confirms which cards/media appeared correctly and sends back only the inbound variants still required by the broader pipeline gate
