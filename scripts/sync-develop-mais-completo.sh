#!/usr/bin/env bash
# Sync develop com main mantendo o mais completo
# Gerado automaticamente em 2026-08-07 - executar na raiz do repo fora do sandbox
set -euo pipefail
echo "=== Stash worktree atual (sticker 5.6) ==="
git stash push -u -m "wip-sticker-5.6-do-main" || true
echo "=== Checkout develop ==="
git checkout develop
echo "=== Merge main mantendo histórico completo ==="
git merge --no-ff main -m "chore: sincroniza develop com main (mantém mais completo)"
echo "=== Cria feature branch para pendência 5.6 ==="
git checkout -b feature/sync-sticker-library-5.6
git stash pop || echo "Nada em stash ou conflito - verifique git stash list"
echo "=== Status ==="
git status --porcelain
git log --oneline --graph --all -n 8
echo "Pronto: develop agora contém main (e05dda7) e feature contém seu trabalho não commitado."
