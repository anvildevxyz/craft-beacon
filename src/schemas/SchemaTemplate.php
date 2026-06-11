<?php

namespace anvildev\beacon\schemas;

use anvildev\beacon\services\ExpressionEvaluator;

class SchemaTemplate
{
    public function __construct(
        protected readonly ExpressionEvaluator $evaluator,
        protected readonly string $type,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,string> $mapping
     * @return array<string,mixed>
     */
    public function render(array $context, array $mapping): array
    {
        $output = ['@context' => 'https://schema.org', '@type' => $this->type];
        foreach ($mapping as $key => $template) {
            if (($value = $this->evaluator->interpolate($template, $context)) !== '') {
                $output[$key] = $value;
            }
        }
        return $output;
    }
}
