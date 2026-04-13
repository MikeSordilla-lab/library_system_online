#!/usr/bin/env bash
set -euo pipefail

BASHRC="$HOME/.bashrc"
touch "$BASHRC"

if grep -q "# mempalace-project-context-start" "$BASHRC"; then
  echo "BASHRC_ALREADY_SET:$BASHRC"
  exit 0
fi

cat >> "$BASHRC" <<'EOF'

# mempalace-project-context-start
mempalace() {
  if [ -x "./mempalace.sh" ]; then
    ./mempalace.sh "$@"
  else
    python -X utf8 -m mempalace "$@"
  fi
}
# mempalace-project-context-end
EOF

echo "BASHRC_UPDATED:$BASHRC"
