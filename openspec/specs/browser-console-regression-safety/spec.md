# Browser Console Regression Safety

## Purpose

Define runtime and frontend compatibility guarantees that prevent known API
availability, router and chart configuration regressions from resurfacing in
the authenticated SPA.

## Requirements

### Requirement: Fiscal portfolio endpoints remain available to the SPA
The authenticated fiscal portfolio endpoints SHALL be executable by the
configured PHP-FPM runtime user while preserving tenant resolution,
authorization and the published response contract.

#### Scenario: Authenticated tenant member opens the PGDAS-D portfolio
- **WHEN** a tenant member requests `overview` and `clients` for `simples_mei` with submodule `PGDASD`
- **THEN** the runtime resolves the required classes and returns the contract-defined response without an internal class-loading error

#### Scenario: Request has no valid session
- **WHEN** the SPA requests a protected endpoint without a valid Sanctum session
- **THEN** the API remains fail-closed and returns the contract-defined authentication error

### Requirement: Previous client certificate links remain compatible
The SPA SHALL resolve `/clients/:id/certificado` without a Vue Router no-match
warning and SHALL replace it with the canonical client detail location.

#### Scenario: Previous certificate deep link is opened
- **WHEN** a user navigates to `/clients/:id/certificado`
- **THEN** the router replaces it with `/clients/:id/cadastro` for the same client

### Requirement: Client growth crosshair uses the chart coordinate domain
The client growth chart SHALL configure its Crosshair with the horizontal
accessor used by the plotted series and axis.

#### Scenario: Pointer moves over the client growth chart
- **WHEN** the chart contains real growth data and the pointer moves over it
- **THEN** the Crosshair resolves the coordinate and renders its template without an accessor configuration warning
