<?php

namespace anvildev\beacon\tracking;

use anvildev\beacon\enums\TrackingPlacement;

interface TrackingScriptProviderInterface
{
    public function getHandle(): string;

    public function getDisplayName(): string;

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>  empty on success, [field => message] on failure
     */
    public function validateConfig(array $config): array;

    /**
     * @return list<TrackingPlacement>|null  null = placement-flexible, list = fixed placements
     */
    public function getFixedPlacements(): ?array;

    /**
     * Path to the Twig template that renders this provider's config fields on
     * the tracking-script edit form, or null when the provider needs no config
     * fields. The template is rendered with `script` and `provider` variables;
     * field inputs must be namespaced under `config[...]`.
     *
     * Third-party providers should return a path within their own registered
     * template root. Returning Beacon's `beacon/tracking/_provider-fields/custom`
     * reuses the built-in raw-snippet field.
     */
    public function getFieldsTemplate(): ?string;

    /**
     * @param array<string, mixed> $config
     */
    public function render(array $config, TrackingPlacement $placement): string;
}
