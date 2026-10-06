#!/usr/bin/env bash
# Runs the tests of the phone's outbox, the local search and the service worker.
# No browser needed: IndexedDB and the worker's environment are simulated.
# Usage (from the project folder, inside the app container):  bash tests/js/eseguire.sh
set -euo pipefail

cd "$(dirname "$0")/../.."
cartella="$(mktemp -d)"
trap 'rm -rf "$cartella"' EXIT

# The two modules are plain TypeScript: bundle them for Node.
npx esbuild resources/js/lib/coda.ts --bundle --format=esm --outfile="$cartella/coda.mjs" --log-level=warning
npx esbuild resources/js/lib/elencoBambini.ts --bundle --format=esm --outfile="$cartella/elenco.mjs" --log-level=warning \
    --alias:@="$PWD/resources/js"

node tests/js/sw.test.mjs

cp tests/js/coda.test.mjs "$cartella/"
cd "$cartella"
npm init -y >/dev/null
npm install fake-indexeddb --no-audit --no-fund >/dev/null
node coda.test.mjs
