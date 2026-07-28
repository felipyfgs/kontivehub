#!/usr/bin/env bash
# Code review local via CodeRabbit CLI (KontiveHub).
# Uso:
#   ./scripts/code-review.sh                 # uncommitted
#   ./scripts/code-review.sh --base main     # vs main
#   ./scripts/code-review.sh --all           # escopo padrão do CLI
#   ./scripts/code-review.sh --human         # saída legível (sem --agent)
#
# Review + fix + commit automático: isso exige um agent (não este script).
# No Cursor/Grok/Claude use a skill auto-review-ship ou o command /ship.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v coderabbit >/dev/null 2>&1; then
  echo "CodeRabbit CLI não encontrado no PATH." >&2
  echo "Instale: https://www.coderabbit.ai/cli" >&2
  exit 127
fi

MODE="agent"
SCOPE_MODE="uncommitted"
BASE_BRANCH=""
EXTRA=()
INCLUDE_UNTRACKED=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --human)
      MODE="human"
      shift
      ;;
    --all)
      SCOPE_MODE="all"
      shift
      ;;
    --base)
      SCOPE_MODE="base"
      BASE_BRANCH="${2:?--base requer branch}"
      shift 2
      ;;
    --include-untracked)
      INCLUDE_UNTRACKED=1
      EXTRA+=(--include-untracked)
      shift
      ;;
    -h|--help)
      sed -n '2,8p' "$0"
      exit 0
      ;;
    *)
      EXTRA+=("$1")
      shift
      ;;
  esac
done

# Paths no escopo efetivo do review (inclui commits vs --base quando aplicável).
collect_scope_paths() {
  local paths=()
  case "$SCOPE_MODE" in
    uncommitted)
      mapfile -t paths < <(
        {
          git diff --name-only
          git diff --cached --name-only
          if [[ "$INCLUDE_UNTRACKED" -eq 1 ]]; then
            git ls-files --others --exclude-standard
          fi
        } | sed '/^$/d' | sort -u
      )
      ;;
    base)
      mapfile -t paths < <(
        {
          git diff --name-only "${BASE_BRANCH}...HEAD"
          git diff --name-only
          git diff --cached --name-only
          if [[ "$INCLUDE_UNTRACKED" -eq 1 ]]; then
            git ls-files --others --exclude-standard
          fi
        } | sed '/^$/d' | sort -u
      )
      ;;
    all)
      mapfile -t paths < <(
        {
          git diff --name-only HEAD
          git diff --cached --name-only
          git ls-files --others --exclude-standard
          # branch tip vs merge-base com main se existir
          if git rev-parse --verify main >/dev/null 2>&1; then
            git diff --name-only "$(git merge-base HEAD main)"...HEAD
          fi
        } | sed '/^$/d' | sort -u
      )
      ;;
  esac
  printf '%s\n' "${paths[@]+"${paths[@]}"}"
}

# Falha hard se o escopo parecer conter secrets (paths ou conteúdo).
assert_no_secrets_in_scope() {
  local -a paths=()
  local p hits=0 base
  # Bloqueia .env reais; permite .env.example / .env.*.example
  local path_re='(^|/)\.env$|(^|/)\.env\.[^/]+$|\.pem$|\.pfx$|\.p12$|(^|/)id_rsa($|\.)|(^|/)auth\.json$|private[_-]?key|(^|/)spool/.*whatsapp'
  # Só valores literais de secret — não scripts que *gerenciam* chaves (Makefile/sed).
  # ghp_/gho_ = GitHub; AKIA = AWS access key id; long base64-ish vault/app keys.
  local content_re='BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY'
  content_re+='|VAULT_MASTER_KEY[[:space:]]*=[[:space:]]*['\''"]?[A-Za-z0-9+/=]{24,}'
  content_re+='|(GITHUB_TOKEN|GH_TOKEN|OPENAI_API_KEY|ANTHROPIC_API_KEY)[[:space:]]*=[[:space:]]*['\''"]?[A-Za-z0-9_\-]{20,}'
  content_re+='|AWS_SECRET_ACCESS_KEY[[:space:]]*=[[:space:]]*['\''"]?[A-Za-z0-9/+=]{30,}'
  content_re+='|AKIA[0-9A-Z]{16}'
  content_re+='|gh[pousr]_[A-Za-z0-9]{20,}'

  mapfile -t paths < <(collect_scope_paths)

  if [[ ${#paths[@]} -eq 0 ]]; then
    return 0
  fi

  for p in "${paths[@]}"; do
    base="$(basename "$p")"
    # allowlist de exemplos versionáveis
    if [[ "$base" == ".env.example" || "$base" == *.env.example || "$base" == .env.*.example ]]; then
      continue
    fi
    if [[ "$p" =~ $path_re ]]; then
      echo "ERRO: possível secret no escopo do review: $p" >&2
      hits=1
      continue
    fi
    # Conteúdo de arquivos no escopo (inclui untracked)
    if [[ -f "$p" ]] && grep -EIq -- "$content_re" "$p" 2>/dev/null; then
      # ignore matches that are only shell vars ($$key, $VAR, ${VAR})
      if grep -En -- "$content_re" "$p" 2>/dev/null \
        | grep -Ev '=\$\$|=\$[A-Za-z_]|=\$\{|sed |grep ' >/dev/null; then
        echo "ERRO: possível material sensível no conteúdo de: $p" >&2
        hits=1
      fi
    fi
  done

  # Diff tracked/staged também (linhas adicionadas)
  local diff_blob=""
  case "$SCOPE_MODE" in
    uncommitted)
      diff_blob="$(git diff; git diff --cached)"
      ;;
    base)
      diff_blob="$(git diff "${BASE_BRANCH}...HEAD"; git diff; git diff --cached)"
      ;;
    all)
      diff_blob="$(git diff HEAD; git diff --cached)"
      if git rev-parse --verify main >/dev/null 2>&1; then
        diff_blob+="$(git diff "$(git merge-base HEAD main)"...HEAD)"
      fi
      ;;
  esac

  if printf '%s' "$diff_blob" | grep -E '^\+' | grep -EIq -- "$content_re"; then
    if printf '%s' "$diff_blob" | grep -E '^\+' | grep -E -- "$content_re" \
      | grep -Ev '=\$\$|=\$[A-Za-z_]|=\$\{|sed |grep ' >/dev/null; then
      echo "ERRO: possível material sensível no conteúdo do diff do escopo." >&2
      hits=1
    fi
  fi

  if [[ "$hits" -ne 0 ]]; then
    echo "Abortando antes da chamada ao CodeRabbit. Remova secrets do escopo e tente de novo." >&2
    exit 1
  fi
}

echo "==> CodeRabbit auth"
coderabbit auth status || {
  echo "Não autenticado. Rode: coderabbit auth login" >&2
  exit 1
}

echo "==> Diff snapshot"
git status -sb
git diff --stat || true
git diff --cached --stat || true
if [[ "$SCOPE_MODE" == "base" ]]; then
  git diff --stat "${BASE_BRANCH}...HEAD" || true
fi

echo "==> Checagem de secrets no escopo"
assert_no_secrets_in_scope

SCOPE_ARGS=()
case "$SCOPE_MODE" in
  uncommitted) SCOPE_ARGS=(--uncommitted) ;;
  base) SCOPE_ARGS=(--base "$BASE_BRANCH") ;;
  all) SCOPE_ARGS=() ;;
esac

ARGS=(review)
if [[ "$MODE" == "agent" ]]; then
  ARGS+=(--agent)
fi
ARGS+=("${SCOPE_ARGS[@]}" "${EXTRA[@]}")

echo "==> coderabbit ${ARGS[*]}"
exec coderabbit "${ARGS[@]}"
