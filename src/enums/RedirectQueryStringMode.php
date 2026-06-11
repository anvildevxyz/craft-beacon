<?php

namespace anvildev\beacon\enums;

/**
 * How a redirect rule treats the query string portion of an incoming URI.
 *
 * - Ignore   — match only on the path; the incoming query string is dropped.
 *              Behaves like every existing rule (default for backwards compat).
 * - Preserve — match on the path; if the resolved target has no query string
 *              of its own, append the incoming one. Useful for keeping
 *              `?utm=…` analytics tags intact across a redirect.
 * - Match    — `sourceUri` may include `?key=value`; the full URI (path +
 *              query, normalised by sorted key) must match for the rule to
 *              fire. Lets you redirect specific query-string combinations
 *              without affecting siblings.
 */
enum RedirectQueryStringMode: string
{
    case Ignore = 'ignore';
    case Preserve = 'preserve';
    case Match = 'match';
}
