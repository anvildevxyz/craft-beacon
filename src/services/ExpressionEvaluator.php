<?php

namespace anvildev\beacon\services;

use yii\base\Component;

/**
 * Renders user-authored mapping templates by substituting `{path}` tokens
 * with values from a provided context. The evaluator is the security
 * boundary between editor-authored mappings and the JSON-LD output.
 *
 * Hardening:
 *  - `interpolate()` walks ARRAYS ONLY. Object-property traversal is rejected
 *    so callers can't accidentally pass an Entry/Element into context and let
 *    editors reach `entry.password` or invoke methods through magic accessors.
 */
class ExpressionEvaluator extends Component
{
    /**
     * Replace `{path}` tokens in a template string with values from $context.
     * Path is dot-notation: `seo.description`, `authors.0.name`. Missing paths
     * resolve to empty string. Object properties are NOT reachable — pass plain
     * arrays only.
     *
     * @param array<string,mixed> $context
     */
    public function interpolate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)\}/',
            function(array $match) use ($context): string {
                $value = $this->resolvePath($context, $match[1]);
                return ($value === null || is_array($value) || is_object($value)) ? '' : (string) $value;
            },
            $template,
        );
    }

    /**
     * Walks $context using dot-notation. Array-only — object properties are
     * intentionally unreachable (see class docblock).
     *
     * @param array<string,mixed> $context
     */
    private function resolvePath(array $context, string $path): mixed
    {
        return array_reduce(
            explode('.', $path),
            static fn(mixed $carry, string $part): mixed =>
                is_array($carry) && array_key_exists($part, $carry) ? $carry[$part] : null,
            $context,
        );
    }
}
