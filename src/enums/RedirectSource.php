<?php

namespace anvildev\beacon\enums;

/**
 * Provenance of a redirect row, persisted to `{{%beacon_redirects}}.source`
 * and surfaced as a filter facet in the CP.
 */
enum RedirectSource: string
{
    case Manual = 'manual';
    case ManualElement = 'manual-element';
    case CsvImport = 'csv-import';
    case AutoSlug = 'auto-slug';
}
