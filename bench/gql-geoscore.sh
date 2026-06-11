#!/usr/bin/env bash
# End-to-end smoke for the `beacon.geoScore` GraphQL field (PR-F).
#
# Asserts the shape of the response and that the field is null without
# the `beaconGeoScore:read` schema component on the token, populated
# with it. Pure curl + jq — no PHPUnit / Craft bootstrap required.
#
# Env vars:
#   ENDPOINT     GraphQL endpoint (default: https://craft-plugin-dev.ddev.site/api)
#   TOKEN        Bearer token WITH beaconGeoScore:read on its schema
#   ANON_TOKEN   Optional second token WITHOUT beaconGeoScore:read
#                (falls back to no Authorization header → public schema)
#   ENTRY_ID     Entry id to query (default: 1)
#   SITE_ID      Site id to query — required when the entry's geo score
#                row lives on a non-primary site (default: omit, primary site)
#
# Exit codes: 0 = pass, 1 = fail, 2 = environment/setup error.

set -euo pipefail

ENDPOINT="${ENDPOINT:-https://craft-plugin-dev.ddev.site/api}"
TOKEN="${TOKEN:-}"
ANON_TOKEN="${ANON_TOKEN:-}"
ENTRY_ID="${ENTRY_ID:-1}"
SITE_ID="${SITE_ID:-}"
site_arg=""
[[ -n "$SITE_ID" ]] && site_arg=", siteId: $SITE_ID"

command -v jq >/dev/null 2>&1 || { echo "jq required" >&2; exit 2; }
command -v curl >/dev/null 2>&1 || { echo "curl required" >&2; exit 2; }

if [[ -z "$TOKEN" ]]; then
    echo "TOKEN env var required (must have beaconGeoScore:read on its schema)" >&2
    exit 2
fi

query='{ entries(id: '"$ENTRY_ID"''"$site_arg"', limit: 1) { id beacon { geoScore { score weakestPillar computedAt pillars { handle score band notes } } } } }'
payload=$(jq -n --arg q "$query" '{query: $q}')

run_query() {
    local label="$1"
    local auth_header="$2"
    local extra_args=()
    [[ -n "$auth_header" ]] && extra_args=(-H "$auth_header")

    curl -sS -X POST "$ENDPOINT" \
        -H 'Content-Type: application/json' \
        "${extra_args[@]}" \
        -d "$payload"
}

echo "→ GET geoScore WITH beaconGeoScore:read"
with_scope=$(run_query "with-scope" "Authorization: Bearer $TOKEN")
echo "$with_scope" | jq -e '.data.entries[0].beacon.geoScore' >/dev/null || {
    echo "FAIL: geoScore should be non-null when token carries beaconGeoScore:read" >&2
    echo "$with_scope" >&2
    exit 1
}
echo "$with_scope" | jq -e '.data.entries[0].beacon.geoScore.score | type == "number"' >/dev/null || {
    echo "FAIL: geoScore.score should be a number" >&2; exit 1
}
echo "$with_scope" | jq -e '.data.entries[0].beacon.geoScore.pillars | type == "array"' >/dev/null || {
    echo "FAIL: geoScore.pillars should be an array" >&2; exit 1
}
echo "  ok — score=$(echo "$with_scope" | jq -r '.data.entries[0].beacon.geoScore.score') weakest=$(echo "$with_scope" | jq -r '.data.entries[0].beacon.geoScore.weakestPillar')"

echo "→ GET geoScore WITHOUT beaconGeoScore:read"
anon_auth=""
[[ -n "$ANON_TOKEN" ]] && anon_auth="Authorization: Bearer $ANON_TOKEN"
without_scope=$(run_query "without-scope" "$anon_auth")
geoScore_null=$(echo "$without_scope" | jq '.data.entries[0].beacon.geoScore')
if [[ "$geoScore_null" != "null" ]]; then
    echo "FAIL: geoScore should be null without beaconGeoScore:read (got: $geoScore_null)" >&2
    echo "$without_scope" >&2
    exit 1
fi
echo "  ok — geoScore is null"

echo "→ GET beacon.title WITHOUT geoScore in selection set (lazy-resolver smoke)"
lazy_query='{ entries(id: '"$ENTRY_ID"''"$site_arg"', limit: 1) { beacon { title } } }'
lazy_payload=$(jq -n --arg q "$lazy_query" '{query: $q}')
lazy=$(curl -sS -X POST "$ENDPOINT" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $TOKEN" \
    -d "$lazy_payload")
echo "$lazy" | jq -e '.data.entries[0].beacon.title | type == "string"' >/dev/null || {
    echo "FAIL: beacon.title should resolve even without geoScore in the query" >&2
    echo "$lazy" >&2; exit 1
}
echo "  ok — beacon.title resolved, geoScore field never invoked"

echo
echo "PASS: geoScore GraphQL smoke (3/3 assertions)"
