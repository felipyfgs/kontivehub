# Container Edge Availability

## Purpose

Define availability guarantees for the Nginx container edge when application containers are recreated or unavailable.

## Requirements

### Requirement: FastCGI upstream follows container address changes
The Nginx edge SHALL resolve the logical PHP service through the Docker DNS
resolver with bounded cache lifetime in development and production
configurations. Recreating PHP with a new container address SHALL NOT require
restarting Nginx for subsequent FastCGI requests to reach the current PHP
instance.

#### Scenario: PHP container is recreated
- **WHEN** the PHP service is recreated with a different internal address while the Nginx container remains running
- **THEN** Nginx resolves the current `php:9000` address within the bounded convergence window and `/up` returns success again

#### Scenario: PHP remains unavailable
- **WHEN** the logical PHP service cannot be resolved or reached
- **THEN** Nginx fails closed with an upstream error and does not fabricate a healthy Laravel response

### Requirement: Edge health verifies the application path
The Nginx container healthcheck SHALL continue to exercise the local `/up`
route through FastCGI and SHALL recover automatically after PHP becomes
reachable. The change MUST preserve existing public routing, FastCGI
parameters, proxy allowlists, timeouts and response contracts.

#### Scenario: Upstream recovery is verified
- **WHEN** a local verification starts with a healthy stack, recreates only PHP and waits for the declared timeout
- **THEN** the same Nginx container becomes healthy again without manual restart and without changing any public API response

#### Scenario: Configuration is invalid
- **WHEN** either development or production Nginx configuration is checked before rollout
- **THEN** the quality gate rejects invalid syntax or an unresolved required upstream policy
