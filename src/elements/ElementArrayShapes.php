<?php

namespace anvildev\beacon\elements;

/**
 * PHPStan array-shape aliases for Beacon element classes.
 * Import via `@phpstan-import-type Foo from \anvildev\beacon\elements\ElementArrayShapes`.
 *
 * @phpstan-type YiiModelRule list<array<int|string, mixed>>
 *
 * @phpstan-type ElementSortOption array{
 *     label: string,
 *     orderBy: string,
 *     attribute: string,
 * }
 *
 * @phpstan-type PersonCredentialNode array{'@type': 'EducationalOccupationalCredential', name: string}
 *
 * @phpstan-type PersonJsonLdNode array{
 *     '@id': string,
 *     '@type': 'Person',
 *     name: string,
 *     jobTitle?: string,
 *     description?: string,
 *     image?: string,
 *     sameAs?: list<string>,
 *     knowsAbout?: list<string>,
 *     hasCredential?: list<PersonCredentialNode>,
 *     alumniOf?: list<string>,
 *     affiliation?: list<string>,
 *     worksFor?: list<string>,
 * }
 *
 * @phpstan-type AuthorRouteDefinition array{
 *     0: 'templates/render',
 *     1: array{template: string, variables: array{author: AuthorElement}},
 * }
 */
final class ElementArrayShapes
{
    private function __construct()
    {
    }
}
