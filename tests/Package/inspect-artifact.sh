#!/usr/bin/env bash

set -euo pipefail

archive="${1:?Usage: inspect-artifact.sh <archive.tar.gz>}"
repository_root="$(git rev-parse --show-toplevel)"
archive_files="$(mktemp)"

trap 'rm -f "$archive_files"' EXIT

tar -tzf "$archive" > "$archive_files"

# These stable package entrypoints should exist regardless of how the internal
# implementation is organized.
required=(
  LICENSE
  README.md
  composer.json
  config/site-methods.php
  index.css
  index.js
  index.php
)

for required_file in "${required[@]}"; do
  if grep -Fxq "$required_file" "$archive_files"; then
    continue
  fi

  echo "Missing required package path: $required_file" >&2
  exit 1
done

runtime_roots=(
  blueprints
  config
  docs
  src
  translations
)

# Every tracked runtime file must be packaged. New classes and documentation
# are covered automatically without extending a second filename allowlist.
while IFS= read -r runtime_file; do
  if grep -Fxq "$runtime_file" "$archive_files"; then
    continue
  fi

  echo "Tracked runtime file missing from package: $runtime_file" >&2
  exit 1
done < <(git -C "$repository_root" ls-files -- "${runtime_roots[@]}")

allowed='^(LICENSE|README\.md|composer\.json|index\.(css|js|php)|blueprints(/.*)?|config(/.*)?|docs(/.*)?|src(/.*)?|translations(/.*)?)$'
unexpected="$(grep -Ev "$allowed" "$archive_files" || true)"

if [[ -n "$unexpected" ]]; then
  echo "Unexpected path found in Composer package artifact:" >&2
  printf '%s\n' "$unexpected" >&2
  exit 1
fi
