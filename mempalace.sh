#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

export PYTHONUTF8=1
export PYTHONIOENCODING=utf-8
export PYTHONLEGACYWINDOWSSTDIO=1

WING="library_system_online"
COMMAND="${1:-context}"
shift || true

run_real_mempalace() {
  python -X utf8 -m mempalace "$@"
}

show_step() {
  local title="$1"
  echo
  echo "==> ${title}"
}

show_context() {
  echo "AI context snapshot for wing '${WING}'"

  show_step "MemPalace status"
  run_real_mempalace status || true

  show_step "Architecture memory"
  run_real_mempalace search "current architecture" --wing "$WING" || true

  show_step "Pitfalls memory"
  run_real_mempalace search "known pitfalls" --wing "$WING" || true

  show_step "Recent decisions memory"
  run_real_mempalace search "recent changes decisions" --wing "$WING" || true

  show_step "Git branch and working tree"
  git status --short --branch 2>/dev/null || echo "Not a git repository or git unavailable."

  show_step "Recent commits"
  git --no-pager log -n 8 --date=short --pretty='format:%h %ad %an %s' 2>/dev/null || echo "No git log available."
}

if [[ "$COMMAND" == "context" ]]; then
  show_context
  exit 0
fi

run_real_mempalace "$COMMAND" "$@"
