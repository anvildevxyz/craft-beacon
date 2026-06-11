<?php

namespace anvildev\beacon\types;

/**
 * Cross-cutting PHPStan array-shape aliases that do not belong to a single
 * domain model. Import via `@phpstan-import-type Foo from ArrayShapes`.
 *
 * @phpstan-type LabelValueOption array{label: string, value: string}
 * @phpstan-type BreadcrumbItem array{name: string, url?: string}
 * @phpstan-type BreadcrumbItemInput array{name?: string, url?: string}
 * @phpstan-type Redirect404LogRow array{
 *     id: int,
 *     uri: string,
 *     hits: int,
 *     firstSeen: string,
 *     lastSeen: string,
 *     referer: ?string,
 * }
 * @phpstan-type Redirect404WithSuggestions array{
 *     id: int,
 *     uri: string,
 *     hits: int,
 *     firstSeen: string,
 *     lastSeen: string,
 *     referer: ?string,
 *     suggestions: list<string>,
 * }
 * @phpstan-type ElementSourceDefinition array{
 *     key: string,
 *     label: string,
 *     criteria: array<string, mixed>,
 *     structureId?: int,
 *     structureEditable?: bool,
 * }
 * @phpstan-type ElementTableAttributeMap array<string, array{label: string}>
 * @phpstan-type RedirectActivityRow array{
 *     id: int,
 *     sourceUri: string,
 *     targetUri: string,
 *     statusCode: int,
 *     lastHit: ?string,
 *     hits: int,
 * }
 */
final class ArrayShapes
{
    private function __construct()
    {
    }
}
