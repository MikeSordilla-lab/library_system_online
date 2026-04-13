#!/usr/bin/env bash
set -euo pipefail

WING="${1:-library_system_online}"

run_mempalace() {
  if command -v mempalace >/dev/null 2>&1; then
    mempalace "$@"
  else
    python -m mempalace "$@"
  fi
}

echo "Starting agent bootstrap for wing '$WING'..."

echo
echo "==> Memory status"
run_mempalace status || true

echo
echo "==> Architecture recall"
run_mempalace search "current architecture" --wing "$WING" || true

echo
echo "==> Pitfalls recall"
run_mempalace search "known pitfalls" --wing "$WING" || true

echo
echo "==> Auth decisions recall"
run_mempalace search "auth flow decisions" --wing "$WING" || true

echo
echo "Bootstrap completed."
