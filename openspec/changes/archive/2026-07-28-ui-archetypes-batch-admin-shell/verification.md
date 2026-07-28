## Evidência local

Validado em 2026-07-28 na stack de desenvolvimento deste checkout.

### Referência e testes

- A referência visual canônica passou na revisão `31970177d818`.
- O gate focado do lote passou em 6 arquivos e 39 testes, incluindo o novo
  `admin-shell-migration-gate.test.ts`.
- A suíte Web completa passou em 113 arquivos e 562 testes.

### Gates Web

- `lint`: passou.
- `typecheck`: passou.
- `generate`: passou nas 55 rotas estáticas.
- `test`: passou.
- `test:fidelity`: passou com 74 páginas e 74 entradas na matriz.
- `test:artifacts`: passou em 426 arquivos de texto, sem material sensível.

### Desktop, mobile e acessibilidade

- As três superfícies foram abertas com a identidade de desenvolvimento já
  existente, sem imprimir credenciais nem alterar dados.
- SERPRO, criação e detalhe de escritório foram inspecionados em 1440×900 e
  390×844, sem overflow horizontal.
- Toolbar condicional, voltar desktop/mobile, labels dos campos, foco por Tab,
  badge de lifecycle e retry fail-closed foram verificados no navegador.
- Sete capturas temporárias foram inspecionadas e removidas; nenhum artefato
  Playwright permaneceu na worktree.
- O navegador no container registrou somente a indisponibilidade esperada do
  WebSocket Reverb em `127.0.0.1:8080`; não houve erro Vue nem falha de página.

### Paridade

- `template-parity-matrix.md` permaneceu inalterada.
- O gate de fidelidade e suas allowlists permaneceram inalterados.
