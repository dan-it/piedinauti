#!/usr/bin/env bash
# Applies the phase 1 files to the project root (the folder containing src/).
# Usage: ./fase1/apply.sh [project-root]   (default: current directory)
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
target="${1:-.}"

if [ ! -f "$target/src/artisan" ]; then
    echo "No Laravel project found in $target/src" >&2
    exit 1
fi

# Copy new and replaced files.
cp -R "$here/src/." "$target/src/"
cp "$here/Makefile" "$target/Makefile"

# Remove starter-kit files that no longer apply (self-registration, landing page).
while read -r file; do
    [ -n "$file" ] && rm -f "$target/src/$file"
done < "$here/REMOVE.txt"

echo "Phase 1 files applied. Next: make artisan ARGS=\"migrate\" && make test"
