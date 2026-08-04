## ADDED Requirements

### Requirement: Public message content is typed and additive
The API v1 SHALL expose rich semantic fields under `content`, keep lifecycle and availability flags under `metadata`, and retain existing `body`, `text`, `caption` and vCard fields for compatibility.

#### Scenario: Contact list is returned
- **WHEN** a message stores multiple contacts
- **THEN** the API returns every contact under `content.contacts` with normalized display fields and phone candidates

#### Scenario: Message action updates semantic content
- **WHEN** a reaction, poll vote or interactive response is ingested
- **THEN** the updated values are exposed under `content` and not duplicated as semantic metadata

### Requirement: Timeline renders all supported families
The web workspace SHALL render semantic content from `message.content` for contacts, location, polls, interactive responses, links, media and rich cards, and SHALL show an explicit placeholder for unsupported or unavailable content.

#### Scenario: Multiple contacts are received
- **WHEN** `content.contacts` contains more than one item
- **THEN** the bubble renders the same number of contact cards without collapsing them into one

### Requirement: Media viewer is reusable and authenticated
The timeline and shared-content panel SHALL use one reusable fullscreen viewer for image, video and audio with authenticated URLs, keyboard navigation, counter, download and accessible controls; image viewing SHALL also support zoom and rotation.

#### Scenario: User opens an image from the timeline
- **WHEN** the user activates an available image or sticker attachment
- **THEN** the fullscreen viewer opens at that attachment and can navigate the currently loaded media set

### Requirement: Private media streams support browser playback
The API SHALL authorize every media request and SHALL support `HEAD` and one valid byte range for audio/video previews, returning `206` and range headers or `416` for an invalid range.

#### Scenario: Browser requests a video segment
- **WHEN** an authorized request includes a satisfiable `Range: bytes=start-end`
- **THEN** the API returns only that segment with `Content-Range`, `Accept-Ranges`, correct length and private no-store caching
