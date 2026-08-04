## ADDED Requirements

### Requirement: Shared contacts are presented safely
The API SHALL parse shared vCards server-side and expose bounded display names and normalized phone candidates without exposing parser failures or internal identifiers.

#### Scenario: vCard contains multiple phone numbers
- **WHEN** an authorized user reads the message
- **THEN** the response includes all valid normalized phone candidates in stable order

### Requirement: Shared contact import is authorized and idempotent
The API SHALL import a selected contact directly from the stored message, require `communication.manage_contacts`, enforce tenant and conversation ownership, and return the existing contact when that WhatsApp identity is already assigned.

#### Scenario: Contact is imported for the first time
- **WHEN** an authorized user selects a valid phone candidate from a visible message
- **THEN** the API creates the contact and returns outcome `created`

#### Scenario: Contact already exists
- **WHEN** the same shared phone is imported again in the same tenant
- **THEN** the API returns the canonical existing contact with outcome `existing` and creates no duplicate

#### Scenario: Unauthorized import is attempted
- **WHEN** a viewer without `communication.manage_contacts` calls the endpoint
- **THEN** the API denies the mutation without revealing contact content from another tenant or conversation
