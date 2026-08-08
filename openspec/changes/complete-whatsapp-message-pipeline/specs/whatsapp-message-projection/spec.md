## ADDED Requirements

### Requirement: Every inbound 1:1 message is classified
The Wazync gateway SHALL classify every field in the pinned `waE2E.Message` descriptor and SHALL route an inbound 1:1 message to a semantic projection, a safe rich card, a structural control, or an explicit unsupported projection.

#### Scenario: New upstream field is introduced
- **WHEN** the pinned whatsmeow descriptor contains a field absent from the static catalog
- **THEN** the reflective contract test fails before the gateway can silently accept it

#### Scenario: Unknown but user-visible 1:1 content arrives
- **WHEN** a cataloged message cannot be safely extracted
- **THEN** Wazync emits an `UNSUPPORTED` message with its allowlisted provider type and no raw protobuf

### Requirement: Structural wrappers do not hide their children
The gateway SHALL unwrap supported future-proof and associated-child wrappers recursively within a bounded depth, while album and placeholder markers SHALL NOT create user-visible phantom messages.

#### Scenario: Album child contains an image
- **WHEN** an `associatedChildMessage` wraps an image or video in a 1:1 chat
- **THEN** the child is projected as the corresponding media family and remains downloadable

### Requirement: Rare conversational types use safe cards
The gateway SHALL represent supported products, orders, payments, invitations, events and call logs received in 1:1 chats as read-only cards containing only allowlisted scalar facts.

#### Scenario: Product or order is received
- **WHEN** a product or order message contains safely extractable display information
- **THEN** the gateway emits an `INTERACTIVE` message with `rich_card` and excludes transactional secrets and executable actions

### Requirement: Scope is determined by peer address
The gateway SHALL reject group, status and newsletter peers while allowing a group invitation or similar card delivered inside a 1:1 peer.

#### Scenario: Group invitation arrives through a direct chat
- **WHEN** a `groupInviteMessage` is received from a valid 1:1 peer
- **THEN** it is presented as a safe invitation card rather than discarded as group traffic
