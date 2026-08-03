# Repository Guidelines

KontiveHub is a multi-tenant accounting platform. Keep changes within the owning application, preserve authorization boundaries, and run relevant CI gates before review.

## Project Structure & Module Organization

- `apps/api/`: Laravel 13 API; domain code is in `app/`, migrations in `database/`, contracts in `resources/contracts/`, and tests in `tests/`.
- `apps/web/`: Nuxt 4/Vue 3 UI; application code is in `app/` and tests in `tests/`. Follow `apps/web/AGENTS.md`.
- `apps/wazync/`: Go WhatsApp gateway, with entrypoints in `cmd/` and packages in `internal/`.
- Application Dockerfiles live with their owners; `docker/nginx/`, the three root Docker manifests, and `.github/workflows/` contain infrastructure and delivery tooling.

## Build, Test, and Development Commands

- `docker compose up -d --build`: build and start the local stack.
- `cd apps/api && composer install`: install PHP dependencies.
- `cd apps/api && php artisan test`: run Laravel tests; use `--filter=Name` for a focused run.
- `cd apps/api && vendor/bin/pint --test`: check PHP formatting.
- `cd apps/web && corepack pnpm install --frozen-lockfile`: install exact frontend dependencies.
- `cd apps/web && pnpm dev`: start Nuxt locally.
- `docker compose exec web app-entrypoint test-gate`: run the complete web gate in the Web container.
- `cd apps/wazync && go test ./... && go vet ./...`: validate Go code.

## Coding Style & Naming Conventions

Use four spaces in PHP and two in Vue/TypeScript. Format with Pint, ESLint, and `gofmt`. Use `PascalCase` for classes/components and `camelCase` for methods/variables. Laravel owns domain logic; the web app must never call Wazync directly.

## Testing Guidelines

Add regression tests for behavior changes. Name PHP tests `*Test.php`, Vitest files `*.test.ts`, and Go tests `*_test.go`. Develop with focused tests, then run every affected CI gate. Prioritize boundary, authorization, and failure-path assertions.

## Commit & Pull Request Guidelines

Use Conventional Commits in pt-BR, for example `fix(backend): corrigir isolamento por tenant`. Branch from `develop` using `feature/*`, `fix/*`, `refactor/*`, or `chore/*`. PRs target `develop`; only `develop` promotes to `main`. Include a description, validation evidence, linked issues when applicable, and screenshots for UI changes. Resolve review threads and required checks before merging.

## Security & Configuration

Never commit `.env` files, tokens, private keys, PFX/PEM material, fiscal payloads, or production credentials. External integrations and fiscal mutations must remain fail-closed unless the task explicitly includes an approved rollout plan.
