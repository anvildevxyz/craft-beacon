<?php

/**
 * One-shot maintenance script: strip every comment that appears inside a
 * function or method body, keeping the function-head docblock and any
 * static-analysis / type-checker directives intact.
 *
 * Usage:
 *   php tools/strip-in-function-comments.php [--dry-run] <path> [<path> ...]
 *
 * What gets removed:
 *   - `//` line comments inside function/method bodies (including closures
 *     and arrow-function bodies)
 *   - `#` line comments (same scope)
 *   - `/* ... *​/` block comments inside function bodies
 *   - `/** ... *​/` doc comments mid-function (rare but exists)
 *
 * What stays:
 *   - File-level header docblocks
 *   - Class / interface / trait docblocks
 *   - Property docblocks
 *   - Function / method head docblocks (the docblock immediately preceding
 *     `function`)
 *   - Comments OUTSIDE function bodies (e.g. between methods)
 *   - **Behaviour-bearing directives** — any comment containing one of:
 *       @phpstan-, @psalm-, @phan-, @phpcs:, @phpstan-ignore,
 *       @noinspection
 *     These are tooling instructions, not narrative; stripping them
 *     silently breaks the build.
 *
 * Implementation: walks PHP_Token output token-by-token, tracks function
 * depth via `{` / `}` balance, and elides T_COMMENT / T_DOC_COMMENT
 * tokens emitted while function depth > 0.
 */

const PRESERVE_PATTERNS = [
    // Static-analysis directives — stripping breaks PHPStan/Psalm/Phan output.
    '@phpstan-',
    '@psalm-',
    '@phan-',
    '@phpcs:',
    '@noinspection',
    // In-function type hints. Inline `/** @var Type $local */` is the canonical
    // way to type a narrowed variable for PHPStan; it's a directive, not a
    // narrative comment, and removing it breaks the type checker on the next run.
    '@var ',
    '@var\t',
    '@type ',
];

function isPreservedComment(string $comment): bool
{
    foreach (PRESERVE_PATTERNS as $needle) {
        if (str_contains($comment, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Strip in-function comments from a single PHP source string.
 * Returns the modified source (or null when no changes were made).
 */
function stripInFunctionComments(string $source): ?string
{
    $tokens = token_get_all($source);
    $out = '';
    $changed = false;

    $functionDepth = 0;   // how many open function bodies we're inside (nested closures count)
    $braceStack = [];     // stack of brace types: 'fn' = function body, 'other' = something else (class body, anonymous-class body, control flow)
    $expectingFnBody = 0; // counter — when > 0, the next '{' starts a function body
    $prevSig = null;      // previous non-whitespace/non-comment token id

    $tokenCount = count($tokens);
    for ($i = 0; $i < $tokenCount; $i++) {
        $tok = $tokens[$i];

        if (is_array($tok)) {
            [$id, $text] = $tok;

            // T_FUNCTION starts a function — its body opens at the next `{`
            // that isn't preceded by a `;` (forward declaration) or `=>`
            // (arrow function — no body, no body comments to worry about).
            if ($id === T_FUNCTION) {
                $expectingFnBody++;
                $out .= $text;
                $prevSig = $id;
                continue;
            }

            // T_FN is the arrow-function keyword — no body so no scope change.
            if ($id === T_FN) {
                $out .= $text;
                $prevSig = $id;
                continue;
            }

            // Double-quoted string interpolation opens with one of these two
            // tokens — `{$var}` is T_CURLY_OPEN, `${var}` is the long form.
            // Each one closes with a plain `}` literal that we'd otherwise
            // miscount as closing a function body. Push a marker frame on
            // open so the matching `}` pops the string frame instead.
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braceStack[] = 'string';
                $out .= $text;
                continue;
            }

            // Detect comments. Only strip when inside a function body.
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                if ($functionDepth > 0 && !isPreservedComment($text)) {
                    $changed = true;
                    // Drop the comment entirely. Don't add anything — we let
                    // the surrounding whitespace tokens stay as they are.
                    continue;
                }
                $out .= $text;
                // Comments don't update $prevSig — they're meta.
                continue;
            }

            // Track non-whitespace/non-comment tokens for context decisions.
            if ($id !== T_WHITESPACE) {
                $prevSig = $id;
            }
            $out .= $text;
            continue;
        }

        // Single-character token — `{`, `}`, `;`, `=`, etc.
        if ($tok === '{') {
            if ($expectingFnBody > 0) {
                $braceStack[] = 'fn';
                $functionDepth++;
                $expectingFnBody--;
            } else {
                $braceStack[] = 'other';
            }
            $out .= $tok;
            $prevSig = '{';
            continue;
        }
        if ($tok === '}') {
            $kind = array_pop($braceStack);
            if ($kind === 'fn') {
                $functionDepth = max(0, $functionDepth - 1);
            }
            $out .= $tok;
            $prevSig = '}';
            continue;
        }
        if ($tok === ';' && $expectingFnBody > 0) {
            // `function foo();` (abstract / interface method) — no body opens.
            $expectingFnBody--;
        }
        $out .= $tok;
        $prevSig = $tok;
    }

    return $changed ? $out : null;
}

// ---- CLI driver ---------------------------------------------------------

$dryRun = false;
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg[0] !== '-') {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    fwrite(STDERR, "Usage: php strip-in-function-comments.php [--dry-run] <path> [<path> ...]\n");
    exit(2);
}

$phpFiles = [];
foreach ($paths as $path) {
    if (is_file($path)) {
        $phpFiles[] = $path;
        continue;
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $phpFiles[] = $f->getPathname();
        }
    }
}

$totalChanged = 0;
$totalScanned = 0;
foreach ($phpFiles as $file) {
    $totalScanned++;
    $src = file_get_contents($file);
    if ($src === false) {
        fwrite(STDERR, "skip (read failed): $file\n");
        continue;
    }
    $updated = stripInFunctionComments($src);
    if ($updated === null) {
        continue;
    }
    if ($dryRun) {
        echo "would update: $file\n";
        $totalChanged++;
        continue;
    }
    if (file_put_contents($file, $updated) === false) {
        fwrite(STDERR, "skip (write failed): $file\n");
        continue;
    }
    echo "updated: $file\n";
    $totalChanged++;
}

fprintf(STDERR, "\nDone — scanned %d files, %s%d.\n", $totalScanned, $dryRun ? 'would change ' : 'updated ', $totalChanged);
