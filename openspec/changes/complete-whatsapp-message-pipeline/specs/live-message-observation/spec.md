## ADDED Requirements

### Requirement: Acceptance uses post-deploy messages
The implementation SHALL record database and Wazync watermarks after deployment and SHALL use only messages created after those watermarks as evidence of live success.

#### Scenario: No new messages exist
- **WHEN** no message is observed after the recorded watermark
- **THEN** verification stops and requests explicit test sends from the user by message type

### Requirement: Live evidence is correlated end to end
For each observed message type, verification SHALL correlate its Wazync event, Laravel projection, semantic keys, attachment state, API response and visual element without printing bodies, phone numbers or JIDs.

#### Scenario: New media appears through realtime
- **WHEN** a user sends media while the target conversation is open
- **THEN** the matching bubble appears without refresh and its decoded/playable state and viewer are verified visually

### Requirement: Evidence distinguishes coverage level
The final verification SHALL classify every requested type as `observado ao vivo`, `fixture apenas` or `falhou` and SHALL NOT describe fixture-only commercial types as live-observed.

#### Scenario: Commercial type cannot be produced
- **WHEN** the user cannot send a product, order or payment message during observation
- **THEN** its contract tests may pass but the report labels it `fixture apenas`
