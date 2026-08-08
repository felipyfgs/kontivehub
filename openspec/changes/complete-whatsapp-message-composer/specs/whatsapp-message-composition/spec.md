## ADDED Requirements

### Requirement: Composer options reflect effective capabilities
The Web composer SHALL derive every outbound option and limit from the authorized inbox capabilities returned by Laravel and SHALL NOT infer support from a local component or file extension alone.

#### Scenario: A family is not enabled for the inbox
- **WHEN** the capability response marks GIF, event or another family unsupported
- **THEN** the composer hides or disables that action with a stable reason and cannot submit its payload

#### Scenario: Capabilities change while a draft is open
- **WHEN** the selected inbox disconnects or a rollout capability becomes unavailable
- **THEN** the draft is preserved, submission is blocked and the operator receives the current reason without data loss

### Requirement: Attachment launcher is organized by message family
The composer SHALL provide one attachment launcher whose first level contains at most four stable task groups and whose capability-gated actions cover document, photos/videos, camera, audio, contacts, location, poll, event, sticker creation and advanced interactive content with equivalent semantics on desktop and mobile.

#### Scenario: Desktop operator opens attachments
- **WHEN** the operator activates the attachment launcher with pointer or keyboard
- **THEN** an anchored menu presents at most four named task groups, each next layer presents at most four actions, and focus can move into, back from and out of the hierarchy predictably

#### Scenario: Mobile operator opens attachments
- **WHEN** the same launcher is activated at a mobile viewport
- **THEN** a touch-safe sheet presents the same stable groups and actions in steps with a title and Back action, without horizontal overflow or obscuring the send controls

#### Scenario: An advanced family is enabled
- **WHEN** an inbox enables interactive business content or another advanced family
- **THEN** the action appears under the stable More group and does not displace or reorder common document, media or client actions

### Requirement: Composer preserves operational destination context
The composer SHALL keep the authorized conversation/client identity and inbox context visible in a compact form, SHALL revalidate that context before submission and SHALL never reuse a draft across another tenant, inbox or destination.

#### Scenario: Operator changes conversation with a structured draft
- **WHEN** the operator switches to another conversation, inbox or tenant while a structured or media draft exists
- **THEN** the original draft remains bound only to its original context and cannot be submitted or displayed as belonging to the new destination

#### Scenario: Operator sends privacy-sensitive media
- **WHEN** the operator enables view-once or confirms another sensitive outbound variant
- **THEN** the preview identifies the authorized destination context, explains the irreversible privacy consequence and requires explicit confirmation before submission

### Requirement: Structured families use validated drafts
Location, contacts, poll, event and interactive messages SHALL be created through typed forms that validate required and bounded fields before producing a composer draft.

#### Scenario: Operator creates a poll
- **WHEN** the operator supplies a question, two or more distinct options and a valid selection limit
- **THEN** the composer previews a `POLL` draft and submits the corresponding structured payload rather than flattening it into text

#### Scenario: Operator selects multiple contacts
- **WHEN** CONTACT capabilities allow multiple items and the operator selects more than one authorized contact
- **THEN** the composer creates one ordered contacts draft without exposing contact values from another tenant

### Requirement: Media is previewed before submission
The composer SHALL preview selected media and documents with type, size, order, caption and applicable variants, SHALL allow an item to be removed before any message is created and SHALL expose actionable progress and terminal state per item after submission begins.

#### Scenario: Operator selects several photos and videos
- **WHEN** the files satisfy the advertised batch, MIME and byte limits
- **THEN** the composer displays an ordered preview, permits captions/removal and submits one idempotent batch intent

#### Scenario: Invalid media is selected
- **WHEN** a file exceeds a limit or its detected family conflicts with the selected action
- **THEN** the composer rejects that item, explains the applicable limit and preserves every valid draft item

#### Scenario: A submitted batch is only partially delivered
- **WHEN** one child fails after local batch acceptance while other children are queued, sent or delivered
- **THEN** each child keeps its truthful state and the operator can retry only failed children idempotently without resending accepted children

### Requirement: Camera and sticker creation degrade safely
Camera capture and sticker creation SHALL use browser-local transformations, release acquired resources and fall back to ordinary file selection when required browser APIs or permissions are unavailable.

#### Scenario: Camera permission is denied
- **WHEN** the operator chooses Camera and the browser denies `getUserMedia`
- **THEN** no stream remains active and the composer offers supported image/video selection without creating a message

#### Scenario: Operator creates a sticker
- **WHEN** the operator crops a supported image and confirms the preview
- **THEN** the browser produces a bounded WebP sticker draft and revokes temporary URLs after removal or send

### Requirement: Expression picker supports emoji, GIF and stickers
The composer SHALL provide a searchable, keyboard-accessible expression picker with recent emoji and capability-gated GIF/sticker tabs.

#### Scenario: Emoji is selected
- **WHEN** the operator selects an emoji from search, category or recents
- **THEN** the emoji replaces the active text selection or is inserted at `selectionStart`/`selectionEnd`, and focus plus the resulting caret return to the editor

#### Scenario: Remote GIF provider is unavailable
- **WHEN** no tenant-approved Laravel GIF provider is configured
- **THEN** remote search is unavailable without any browser request to a third party, while supported local upload remains usable

### Requirement: Voice recording has an explicit lifecycle
The composer SHALL implement recording, pause, resume, preview/playback, discard and send states with duration/waveform feedback, actual MIME detection and guaranteed media resource cleanup.

#### Scenario: Operator pauses and sends a voice note
- **WHEN** a supported browser records audio, the operator pauses or previews it and then sends
- **THEN** one AUDIO message is submitted with `ptt=true`, the actual MIME/extension and no active microphone track afterward

#### Scenario: Recording exceeds a limit
- **WHEN** duration or bytes reach the capability limit
- **THEN** recording stops safely, submission cannot exceed the limit and the operator may discard or send only a valid blob

### Requirement: Existing composer workflows remain compatible
The new composer SHALL preserve internal notes, canned responses, slash shortcuts, quoted replies, keyboard send behavior and one draft per conversation without mixing private and WhatsApp content.

#### Scenario: Conversation changes with a pending draft
- **WHEN** the operator switches conversations and later returns
- **THEN** the correct conversation draft and citation are restored without reusing another conversation's files or structured values

#### Scenario: Submission fails
- **WHEN** Laravel rejects or cannot accept a valid-looking draft
- **THEN** the composer preserves editable content, identifies the failed family/item and permits an idempotent retry

### Requirement: Submission lifecycle is visible and actionable
The composer SHALL present capability-derived pt-BR labels for validation, upload, queue acceptance, send, receipt, blocking, cancellation and failure states and SHALL pair every recoverable failure with its safe next action.

#### Scenario: Attachment upload is in progress
- **WHEN** Laravel is receiving one or more draft attachments
- **THEN** the preview shows progress per item, disables duplicate submission and announces meaningful state changes without relying on color alone

#### Scenario: Capability disappears during composition
- **WHEN** a valid draft becomes blocked because the inbox disconnects or a rollout changes
- **THEN** the composer preserves the draft, names the current reason in operational language and offers only actions that cannot create a duplicate or invalid send

#### Scenario: Queue acceptance is delayed
- **WHEN** upload completed but JetStream has not accepted the command
- **THEN** the UI distinguishes waiting for queue from sent or delivered and does not clear the editable draft prematurely

### Requirement: Composer controls are accessible and responsive
All composer menus, dialogs, previews and recording controls SHALL expose labels, focus order, keyboard operation, relevant live announcements, reduced-motion behavior, mobile touch targets of at least 44 by 44 CSS pixels, safe-area handling and functional layout at 200 percent browser zoom.

#### Scenario: Keyboard-only poll composition
- **WHEN** an operator opens the launcher, creates a poll and returns to the composer using only the keyboard
- **THEN** focus remains visible and predictable and every action has an accessible name

#### Scenario: Mobile keyboard or safe area reduces the viewport
- **WHEN** the bottom sheet or editor is used with a virtual keyboard and device safe-area insets
- **THEN** context, validation and primary/cancel actions remain reachable without horizontal overflow or hidden controls

#### Scenario: Modal flow is dismissed
- **WHEN** an operator uses Escape, Back or Cancel in a menu, editor, picker or recorder preview
- **THEN** the temporary surface closes safely, acquired resources are released when appropriate and focus returns to its triggering control

### Requirement: Temporary binary draft lifetime is explicit
Binary attachments, camera captures and recordings SHALL remain private to the live browser session, SHALL NOT be persisted in browser storage and SHALL expose truthful interruption behavior without promising recovery after the browser process or tab is destroyed.

#### Scenario: Operator switches conversations inside the live SPA
- **WHEN** the original browser session remains alive and the operator later returns to the draft conversation
- **THEN** the conversation-scoped binary draft remains available within advertised memory limits and no other conversation can access it

#### Scenario: Controlled navigation may destroy an unsent binary draft
- **WHEN** the operator attempts a navigation or reload that can discard an unsent attachment or recording
- **THEN** the interface warns about that concrete loss and provides stay or discard choices when the browser allows interception
