<?php
/**
 * Regenerates src/schemas/GeneratedSchemaCatalogue.php from schema.org's
 * published JSON-LD release.
 *
 * Usage (from plugin root):
 *
 *   php tools/generate-schema-catalogue.php
 *
 * Override the source URL or supply a local file to skip the fetch:
 *
 *   SCHEMA_ORG_JSONLD_URL=https://schema.org/version/29.0/schemaorg-current-https.jsonld \
 *     php tools/generate-schema-catalogue.php
 *
 *   SCHEMA_ORG_JSONLD_FILE=./schemaorg-current-https.jsonld \
 *     php tools/generate-schema-catalogue.php
 *
 * The generator is run by hand when the maintainer wants to track a new
 * schema.org release. The output PHP file is committed; end users never
 * run this script.
 */

declare(strict_types=1);

$defaultUrl = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
$url = getenv('SCHEMA_ORG_JSONLD_URL') ?: $defaultUrl;
$localPath = getenv('SCHEMA_ORG_JSONLD_FILE') ?: null;
$outputPath = __DIR__ . '/../src/schemas/GeneratedSchemaCatalogue.php';

fwrite(STDERR, "[generate-schema-catalogue] " . ($localPath ? "reading $localPath" : "fetching $url") . "\n");
$raw = $localPath !== null ? @file_get_contents($localPath) : @file_get_contents($url);
if ($raw === false) {
    fwrite(STDERR, "[generate-schema-catalogue] FAILED to read source\n");
    exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['@graph']) || !is_array($data['@graph'])) {
    fwrite(STDERR, "[generate-schema-catalogue] FAILED to parse @graph from JSON-LD\n");
    exit(1);
}

$types = [];        // typeName => ['parents' => [...], 'comment' => string]
$properties = [];   // propertyName => ['domains' => [...], 'comment' => string]

foreach ($data['@graph'] as $node) {
    $type = $node['@type'] ?? null;
    $id = isset($node['@id']) ? (string) $node['@id'] : null;
    if ($id === null) {
        continue;
    }
    // schema.org IDs are mostly "schema:Foo" or "https://schema.org/Foo".
    $name = stripPrefix($id);
    if ($name === null) {
        continue;
    }
    // Skip retired / pending? Schema.org marks status via supersededBy; include
    // anyway and let the renderer not care — the catalogue is permissive.

    if (isClassNode($type)) {
        $types[$name] = [
            'parents' => collectIds($node['rdfs:subClassOf'] ?? []),
        ];
    } elseif (isPropertyNode($type)) {
        $properties[$name] = [
            'domains' => collectIds($node['http://schema.org/domainIncludes'] ?? $node['schema:domainIncludes'] ?? []),
        ];
    }
}

fwrite(STDERR, sprintf("[generate-schema-catalogue] parsed %d types, %d properties\n", count($types), count($properties)));

// Resolve inheritance: walk parents up to Thing, accumulate properties whose
// domainIncludes covers any ancestor.
$ancestorsCache = [];
$ancestorsOf = function(string $name) use (&$ancestorsOf, &$ancestorsCache, $types): array {
    if (isset($ancestorsCache[$name])) {
        return $ancestorsCache[$name];
    }
    $out = [$name];
    foreach ($types[$name]['parents'] ?? [] as $parent) {
        if ($parent === $name || !isset($types[$parent])) {
            continue;
        }
        foreach ($ancestorsOf($parent) as $grand) {
            if (!in_array($grand, $out, true)) {
                $out[] = $grand;
            }
        }
    }
    return $ancestorsCache[$name] = $out;
};

// Invert: type => [propertyName, ...]
$catalogue = array_fill_keys(array_keys($types), []);
foreach ($properties as $propName => $propMeta) {
    foreach ($propMeta['domains'] as $domain) {
        if (!isset($types[$domain])) {
            continue;
        }
        // The property applies to $domain AND all subclasses of $domain. To
        // make lookup O(1) per type, attach the property to every descendant.
        foreach ($types as $candidateName => $_) {
            if (in_array($domain, $ancestorsOf($candidateName), true) && !in_array($propName, $catalogue[$candidateName], true)) {
                $catalogue[$candidateName][] = $propName;
            }
        }
    }
}

// Sort for deterministic output (diffs stay readable across runs).
ksort($catalogue);
foreach ($catalogue as $k => $v) {
    sort($v);
    $catalogue[$k] = $v;
}

$version = guessVersion($raw);
$generated = gmdate('c');

$exported = exportNested($catalogue);

$contents = <<<PHP
<?php

// AUTO-GENERATED — do not edit by hand.
// Source: schema.org JSON-LD release{$version}
// Generated: {$generated}
// Regenerate via: php tools/generate-schema-catalogue.php

declare(strict_types=1);

namespace anvildev\\beacon\\schemas;

/**
 * Flat type → property-name catalogue derived from schema.org's published
 * JSON-LD release. Properties are inherited down the subClassOf chain so
 * each type's list is the full effective set (no need to walk ancestors at
 * lookup time).
 *
 * This complements {@see SchemaPropertyRegistry} — the hand-curated registry
 * supplies the required/recommended/optional tiers + help copy + suggest
 * paths for the 26 high-leverage types. This generated catalogue is the
 * permissive fallback that lets sites opt into the full schema.org graph via
 * `config/beacon.php`'s `fullSchemaCatalogue` flag.
 */
final class GeneratedSchemaCatalogue
{
    /**
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return {$exported};
    }

    /**
     * @return list<string>
     */
    public static function propertiesFor(string \$type): array
    {
        return self::all()[\$type] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::all());
    }
}

PHP;

if (file_put_contents($outputPath, $contents) === false) {
    fwrite(STDERR, "[generate-schema-catalogue] FAILED to write $outputPath\n");
    exit(1);
}

fwrite(STDERR, "[generate-schema-catalogue] wrote $outputPath (" . count($catalogue) . " types)\n");
exit(0);


// ---- helpers ----

function stripPrefix(string $id): ?string
{
    if (str_starts_with($id, 'schema:')) {
        return substr($id, 7);
    }
    if (str_starts_with($id, 'http://schema.org/')) {
        return substr($id, strlen('http://schema.org/'));
    }
    if (str_starts_with($id, 'https://schema.org/')) {
        return substr($id, strlen('https://schema.org/'));
    }
    // External vocab (rdfs:, rdf:, owl:, dcterms:, etc.) — not part of the catalogue.
    return null;
}

function isClassNode(mixed $type): bool
{
    return matchesTypeMarker($type, 'rdfs:Class');
}

function isPropertyNode(mixed $type): bool
{
    return matchesTypeMarker($type, 'rdf:Property');
}

function matchesTypeMarker(mixed $type, string $marker): bool
{
    if (is_string($type)) {
        return $type === $marker;
    }
    if (is_array($type)) {
        return in_array($marker, $type, true);
    }
    return false;
}

function collectIds(mixed $node): array
{
    if ($node === [] || $node === null) {
        return [];
    }
    // Single {@id: ...} or list of them.
    if (isset($node['@id'])) {
        $name = stripPrefix((string) $node['@id']);
        return $name !== null ? [$name] : [];
    }
    $out = [];
    foreach ((array) $node as $entry) {
        if (is_array($entry) && isset($entry['@id'])) {
            $name = stripPrefix((string) $entry['@id']);
            if ($name !== null) {
                $out[] = $name;
            }
        }
    }
    return $out;
}

function guessVersion(string $raw): string
{
    // schema.org JSON-LD embeds the version in its @context or a top-level
    // "schemaversion" key on at least the recent releases. Best-effort.
    if (preg_match('~"schemaversion"\s*:\s*"([^"]+)"~', $raw, $m)) {
        return " v{$m[1]}";
    }
    if (preg_match('~/version/(\d+(\.\d+)*)/~', $raw, $m)) {
        return " v{$m[1]}";
    }
    return '';
}

function exportNested(array $data): string
{
    // var_export(true) but with array short syntax + readable indentation.
    $exported = var_export($data, true);
    $exported = preg_replace("/array \(/", '[', $exported);
    $exported = preg_replace("/\n(\s*)\)/", "\n\$1]", $exported);
    $exported = preg_replace("/=>\s*\n\s*\[/", '=> [', $exported);
    $exported = str_replace("  '", "    '", $exported);
    return rtrim((string) $exported, ',');
}
