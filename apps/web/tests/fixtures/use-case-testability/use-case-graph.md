# Grafo de testabilidade dos casos de uso

Snapshot: `64353885c6f26f97ee75451be9267e276e149c457cb1e9fca790077c58b44060`

O levantamento classifica **572 rotas API**, **74 páginas Nuxt** e **15 clientes HTTP** em **11 jornadas**. 4 jornadas são críticas e exigem evidência L1–L3.

| Jornada | Crítica | Rotas | Páginas | Clientes HTTP | L0 | L1 | L2 | L3 | Lacunas |
|---|:---:|---:|---:|---:|:---:|:---:|:---:|:---:|---|
| Ativação e onboarding público (`public-access`) | não | 5 | 2 | 2 | ✓ | — | — | — | L1, L2, L3 |
| Identidade, conta e troca de escritório (`identity-tenancy`) | sim | 14 | 10 | 1 | ✓ | ✓ | ✓ | ✓ | nenhuma |
| Governança global da plataforma (`platform-governance`) | não | 17 | 12 | 1 | ✓ | ✓ | ✓ | — | L3 |
| Configuração e onboarding do escritório (`tenant-operations`) | não | 31 | 0 | 1 | ✓ | ✓ | ✓ | — | L3 |
| Catálogo e ciclo de vida de clientes (`client-lifecycle`) | sim | 21 | 10 | 1 | ✓ | ✓ | ✓ | ✓ | nenhuma |
| Documentos, notas e exportações (`documents-notes`) | não | 18 | 6 | 1 | ✓ | — | — | — | L1, L2, L3 |
| Atendimento WhatsApp compartilhado (`communication-inbox`) | não | 102 | 8 | 1 | ✓ | ✓ | ✓ | — | L3 |
| Monitoramento fiscal e consultas (`fiscal-monitoring`) | sim | 199 | 17 | 3 | ✓ | ✓ | ✓ | ✓ | nenhuma |
| Fila e processos operacionais (`operational-work`) | sim | 64 | 8 | 3 | ✓ | ✓ | ✓ | ✓ | nenhuma |
| Captura, integrações e documentos de saída (`outbound-capture`) | não | 42 | 1 | 1 | ✓ | — | — | — | L1, L2, L3 |
| Autorização e consumo SERPRO (`serpro-governance`) | não | 59 | 0 | 2 | ✓ | — | — | — | L1, L2, L3 |

## Leitura dos níveis

- `L0`: superfície inventariada e classificada.
- `L1`: contrato HTTP com autenticação, tenant e papel.
- `L2`: regra de domínio ou comportamento do cliente web.
- `L3`: jornada executada no navegador pelo Playwright local.

## Limites e segurança

- Lacunas não críticas permanecem explícitas; referência textual não conta como cobertura behavioral.
- Playwright permanece fora do CI e bloqueia hosts externos.
- SERPRO, Integra, SEFAZ, portal MEI e comunicação permanecem fail-closed nos testes.
- Endpoints de clientes HTTP sem correspondência estrutural: **0**.
