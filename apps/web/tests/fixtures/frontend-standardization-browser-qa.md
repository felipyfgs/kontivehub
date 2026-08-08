# QA Playwright — standardize-frontend-ui-archetypes

Data: 2026-08-04. Browser: Chromium headless, locale pt-BR, modo claro,
`prefers-reduced-motion: reduce`, seed local e viewports 390×900, 768×900 e
1440×900.

## Matriz responsiva

| Rota | 390 | 768 | 1440 | Resultado |
|---|---|---|---|---|
| `/login` | screenshot | screenshot | screenshot | foco de teclado visível; sem overflow |
| `/` | screenshot | screenshot | screenshot | Home real-data; sem overflow |
| `/clients` | screenshot | screenshot | screenshot | cards/tabela preservam ações; sem overflow |
| `/monitoring/mailbox` | screenshot | screenshot | screenshot | vazio e status coerentes; sem overflow |
| `/conta` | screenshot | screenshot | screenshot | select móvel / tabs desktop; sem overflow |
| `/admin/fiscal-modules` | screenshot | screenshot | screenshot | cards móveis / tabela desktop; sem overflow |

Nenhuma das 18 combinações apresentou `scrollWidth > innerWidth`. A primeira
passagem revelou tabs truncadas em Conta/390; a correção substituiu a faixa por
`NavigationSectionNavigation`, e a confirmação encontrou um select móvel, zero
nav desktop visível e zero overflow.

## Teclado, movimento e estados

- Tab inicial em autenticação alcança o botão de aparência com nome acessível;
  os campos E-mail/Senha e Entrar seguem na ordem do documento.
- O contexto inteiro foi criado com movimento reduzido; loaders mantêm texto e
  status, enquanto animação contínua/transições decorativas são removidas.
- Mailbox vazia expôs duas regiões `role=status`; as demais rotas chegaram ao
  estado estável sem `aria-busy=true` residual.
- O QA de deep links sem tenant/permissão registrou zero requests protegidas em
  lista, detalhe, editor e respostas rápidas.

## Artefatos

Screenshots locais (ignorados pelo Git):
`apps/web/.playwright-cli/standardize-frontend-ui-archetypes/{390,768,1440}/`.

