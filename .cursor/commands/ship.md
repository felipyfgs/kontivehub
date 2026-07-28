# Auto review ship

Modo hands-off: code review → corrige bugs → gates → commit(s).

Siga a skill `.agents/skills/auto-review-ship/SKILL.md` integralmente.

Regras rígidas:
- Commit sim (Conventional Commits pt-BR).
- Push **não**, exceto pedido explícito com **remoto e branch de destino**
  (ex.: `git push origin main`). Só a palavra “push” **não** autoriza.
- Sem secrets no stage.
- Paths explícitos (não `git add -A` cego).
- Máx. 3 ciclos de review via `make code-review` (wrapper + CodeRabbit).
- Resumo final com hashes dos commits.
