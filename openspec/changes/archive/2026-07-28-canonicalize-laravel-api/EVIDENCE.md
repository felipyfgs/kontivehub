# Evidência final — canonicalize-laravel-api

Data: 2026-07-28

## Requisitos por capability

### laravel-http-boundaries
- Form Requests em boundaries de plataforma, clientes, Work, Communication, fiscal, outbound, import/export, SEFAZ SVRS/CT-e.
- Gate `ControllerBoundaryArchitectureTest` (inline validation não aumenta; sem Http facade em controllers).
- Exceptions tipadas / FormRequest failOnUnknownFields em local/testing.
- Rate limiters e CSRF documentados em lotes anteriores.

### laravel-data-integrity
- `Model::preventLazyLoading` em local/testing (`AppServiceProvider`).
- Classificação de models: `resources/code-quality/model-classification.json`.
- Cache: `config/cache_keys.php` com prefixo tenant, TTL e locks.
- Bypass de scopes: gate exige tenant_id/PK/limit/chunkById.
- Scans: gate de Commands; export com lazyById.

### laravel-runtime-operations
- Controllers sem SDKs HTTP diretos (gate).
- Jobs com tries + timeout (gate); tags e `failed()` sanitizado nos jobs completados.
- Horizon `readiness_thresholds` em `config/horizon.php`.
- Scheduler: `withoutOverlapping(..., releaseOnTerminationSignals: true)` + `onOneServer` em `routes/console.php`.
- Testes: `ScheduleLockArchitectureTest`, `RuntimeCanonicalizationArchitectureTest`.

## Gates executados
- `composer validate --strict --no-check-publish` — OK
- `tools/code-quality/validate-artifacts.php` — OK
- Testes de arquitetura CodeQuality + MitConsultApiTest — 17 passed
- Suite completa: reexecutar em ambiente sem corrida de DB (múltiplos `php artisan test` no host/stack causam `relation does not exist` intermitente)

## CodeRabbit
- Diff local amplo; revisão CodeRabbit de PR recomendada no handoff se houver PR aberto.
  Neste checkout a validação de architecture gates e testes focados substitui a evidência de regressão de boundary.

## Status
Lotes 1–11 implementados e rastreados em `tasks.md`. Fechamento 12.2–12.5 depende de suite PHPUnit exclusiva no ambiente.
