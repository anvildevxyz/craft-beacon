<?php

namespace anvildev\beacon\services;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectSource;
use anvildev\beacon\enums\RedirectStatusCode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\helpers\RedirectTargets;
use anvildev\beacon\helpers\SafeRegex;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\ImportResult;
use anvildev\beacon\Plugin;
use Craft;
use craft\enums\PropagationMethod;
use yii\base\Component;

/**
 * @phpstan-import-type ImportError from \anvildev\beacon\models\ImportResult
 */
class RedirectImporter extends Component
{
    /** @return list<int> */
    private static function validStatusCodes(): array
    {
        return array_map(static fn(RedirectStatusCode $c): int => $c->value, RedirectStatusCode::cases());
    }

    /**
     * Serialise redirect records to a CSV string round-trippable through {@see importFromCsv()}.
     *
     * @param iterable<RedirectElement> $records
     */
    public function exportToCsv(iterable $records): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary stream for CSV export.');
        }
        fputcsv($handle, ['source', 'target', 'statusCode', 'queryStringMode'], ',', '"', '\\');
        foreach ($records as $r) {
            $type = RedirectType::tryFrom((string) $r->type);
            $source = $type === RedirectType::Regex
                ? 'regex:' . (string) $r->sourceUri
                : (string) $r->sourceUri;
            $qsMode = RedirectQueryStringMode::tryFrom((string) $r->queryStringMode)
                ?? RedirectQueryStringMode::Ignore;
            fputcsv(
                $handle,
                [
                    self::neutraliseCsvFormula($source),
                    self::neutraliseCsvFormula((string) $r->targetUri),
                    (int) $r->statusCode,
                    $qsMode->value,
                ],
                ',',
                '"',
                '\\',
            );
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        return $content;
    }

    /**
     * Prefixes cells that a spreadsheet app would interpret as a formula
     * (`=`, `+`, `-`, `@`, `\t`, `\r`) with a literal single quote so the
     * value is treated as plain text. Legitimate redirects (`/old`, `https://…`,
     * `regex:^/old/(\d+)$`) never start with these characters, so this only
     * affects deliberately-crafted payloads.
     */
    private static function neutraliseCsvFormula(string $value): string
    {
        return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1
            ? "'" . $value
            : $value;
    }

    /**
     * Imports redirects from a CSV string produced by {@see exportToCsv()}.
     *
     * Validated rows are inserted inside a single transaction, so a mid-import
     * failure rolls the whole batch back rather than leaving a partial set.
     * Rows that fail to persist are reported individually in
     * {@see ImportResult::$errors} alongside the parse-time validation errors.
     *
     * @throws \Throwable if the import transaction cannot be committed.
     */
    public function importFromCsv(string $csvContent, int $siteId): ImportResult
    {
        $parsed = $this->parseCsv($csvContent);
        $inserted = 0;
        $errors = $parsed['errors'];

        $baseSortOrder = Plugin::$plugin?->redirects->nextSortOrder() ?? 0;

        Craft::$app->getDb()->transaction(function() use ($parsed, $siteId, $baseSortOrder, &$inserted, &$errors): void {
            $elements = Craft::$app->getElements();
            foreach ($parsed['valid'] as $i => $row) {
                $el = new RedirectElement();
                $el->siteId = $siteId;
                $el->propagationMethod = PropagationMethod::None;
                $el->sourceUri = $row['sourceUri'];
                $el->targetUri = $row['targetUri'];
                $el->statusCode = $row['statusCode'];
                $el->type = $row['type']->value;
                $el->source = RedirectSource::CsvImport->value;
                $el->queryStringMode = $row['queryStringMode']->value;
                $el->sortOrder = $baseSortOrder + $i;
                if ($elements->saveElement($el, false)) {
                    $inserted++;
                } else {
                    $errors[] = [
                        'lineNumber' => 0,
                        'reason' => Craft::t('beacon', 'import.redirects.failed.save.redirect.source', [
                            'source' => $row['sourceUri'],
                        ]),
                    ];
                }
            }
        });

        return new ImportResult(
            insertedCount: $inserted,
            skippedCount: count($errors),
            errors: $errors,
        );
    }

    /**
     * @return array{valid:list<array{sourceUri:string,targetUri:string,statusCode:int,type:RedirectType,queryStringMode:RedirectQueryStringMode}>, errors:list<ImportError>}
     */
    private function parseCsv(string $csv): array
    {
        // Strip a single leading UTF-8 BOM (\xEF\xBB\xBF) that Excel/Numbers/
        // Windows tools prepend to the first cell, otherwise the `source`
        // header never matches and the whole file is rejected.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv, 1) ?? $csv;

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return ['valid' => [], 'errors' => [['lineNumber' => 1, 'reason' => Craft::t('beacon', 'import.redirects.empty.csv.missing.rows')]]];
        }

        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $sourceIdx = array_search('source', $header, true);
        $targetIdx = array_search('target', $header, true);
        $statusIdx = array_search('statusCode', $header, true);
        $qsModeIdx = array_search('queryStringMode', $header, true);

        if ($sourceIdx === false || $targetIdx === false) {
            return ['valid' => [], 'errors' => [['lineNumber' => 1, 'reason' => Craft::t('beacon', 'import.redirects.header.must.contain.source.target')]]];
        }

        $defaultStatusCode = RedirectStatusCode::MovedPermanently->value;
        $validStatusCodes = self::validStatusCodes();
        $validModesStr = implode(', ', array_map(
            static fn(RedirectQueryStringMode $m): string => $m->value,
            RedirectQueryStringMode::cases(),
        ));

        $valid = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            $lineNum = $i + 2;
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line, ',', '"', '\\');
            $source = trim($cols[$sourceIdx] ?? '');
            $target = trim($cols[$targetIdx] ?? '');
            $status = $statusIdx !== false ? trim($cols[$statusIdx] ?? '') : '';
            $qsModeRaw = $qsModeIdx !== false ? trim($cols[$qsModeIdx] ?? '') : '';

            if ($source === '') {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.source.empty')];
                continue;
            }
            if ($target === '') {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.target.empty')];
                continue;
            }

            $statusCode = $status !== '' ? (int) $status : $defaultStatusCode;
            if (!in_array($statusCode, $validStatusCodes, true)) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.invalid.statuscode.must', [
                    'status' => $status,
                    'codes' => implode(', ', $validStatusCodes),
                ])];
                continue;
            }

            $qsMode = $qsModeRaw === '' ? RedirectQueryStringMode::Ignore : RedirectQueryStringMode::tryFrom($qsModeRaw);
            if ($qsMode === null) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.invalid.querystringmode.must', [
                    'mode' => $qsModeRaw,
                    'validModes' => $validModesStr,
                ])];
                continue;
            }

            if (str_starts_with($source, 'regex:')) {
                $sourceUri = substr($source, 6);
                $regexError = SafeRegex::validate($sourceUri);
                if ($regexError !== null) {
                    $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.invalid.regex.pattern', [
                        'detail' => $regexError,
                    ])];
                    continue;
                }
                $type = RedirectType::Regex;
            } else {
                $type = str_contains($source, '*') ? RedirectType::Glob : RedirectType::Exact;
                $sourceUri = $source;
            }

            // mb_strlen so the limits count characters, matching the field
            // validator (BeaconRedirectSourcesField) rather than raw byte length.
            if (mb_strlen($sourceUri) > 255) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.source.exceeds.255.characters')];
                continue;
            }
            if (mb_strlen($target) > 500) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.target.exceeds.500.characters')];
                continue;
            }
            if (Strings::containsLineBreaks($sourceUri)) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.source.contains.invalid.line.breaks')];
                continue;
            }
            if (Strings::containsLineBreaks($target)) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => Craft::t('beacon', 'import.redirects.target.contains.invalid.line.breaks')];
                continue;
            }
            $targetError = RedirectTargets::validateTargetUri($target);
            if ($targetError !== null) {
                $errors[] = ['lineNumber' => $lineNum, 'reason' => $targetError];
                continue;
            }

            $valid[] = [
                'sourceUri' => $sourceUri,
                'targetUri' => $target,
                'statusCode' => $statusCode,
                'type' => $type,
                'queryStringMode' => $qsMode,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }
}
