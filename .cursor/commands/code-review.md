# Code review automático

Rode o loop completo de code review no monorepo KontiveHub (diff atual).

Siga a skill `.agents/skills/auto-code-review/SKILL.md` se existir; senão:

1. `git status -sb` e `git diff` (+ cached). Pare se houver secrets.
2. Review canônico (wrapper com barreira de secrets):
   - `make code-review` (diff local / uncommitted)
   - `make code-review ARGS='--base main'` se eu pedir a branch
3. Corrija Critical e Warning reais; ignore nits de estilo cobertos por lint.
4. Re-review até limpar ou 3 ciclos.
5. Gates só dos apps tocados (ver `AGENTS.md`).
6. Resumo: corrigido / residual / gates.

Não faça commit nem push.
