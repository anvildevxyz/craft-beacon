# Tracking provider extensibility cookbook

Beacon ships five built-in tracking providers (GA4, GTM, Facebook Pixel, Matomo, Custom). Third-party modules can register their own — for in-house analytics, consent-mode wrappers, or providers Beacon doesn't bundle.

## When to add a provider

You want a provider when:

- The script needs **per-environment** + **per-site** toggles that Beacon already orchestrates.
- Editors should configure the script from `/admin/beacon/tracking` rather than custom CP screens.
- You want the script to live in **project config** (sync across environments) and be cacheable via Beacon's render cache.

If you just need to drop a one-off snippet, use the built-in **Custom** provider — it accepts raw HTML/JS and skips the schema/validation pipeline. The cookbook below covers the case where you want a typed config with validation.

## Interface

`anvildev\beacon\tracking\TrackingScriptProviderInterface` (see `src/tracking/TrackingScriptProviderInterface.php`):

```php
interface TrackingScriptProviderInterface
{
    public function getHandle(): string;
    public function getDisplayName(): string;

    /** @param array<string,mixed> $config
     *  @return array<string,string>  field => error message */
    public function validateConfig(array $config): array;

    /** @return list<TrackingPlacement>|null  null = flexible, list = forced */
    public function getFixedPlacements(): ?array;

    /** @param array<string,mixed> $config */
    public function render(array $config, TrackingPlacement $placement): string;
}
```

Placements (`anvildev\beacon\enums\TrackingPlacement`) are `Head`, `BodyStart`, `BodyEnd`. They map to `craft.beacon.head()`, `craft.beacon.bodyStart()`, `craft.beacon.bodyEnd()`.

## Minimal example — Plausible analytics

Plausible's snippet has one config value (the domain) and lives in `<head>`. Three pieces: a provider class, a CP form template, and event-registration in your module.

### 1. The provider

```php
<?php
namespace mymodule\beacon;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\tracking\TrackingScriptProviderInterface;
use Craft;

final class PlausibleProvider implements TrackingScriptProviderInterface
{
    public function getHandle(): string
    {
        return 'plausible';
    }

    public function getDisplayName(): string
    {
        return Craft::t('beacon', 'Plausible');
    }

    public function validateConfig(array $config): array
    {
        $domain = (string)($config['domain'] ?? '');
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return ['domain' => Craft::t('beacon', 'Domain must look like example.com.')];
        }
        return [];
    }

    public function getFixedPlacements(): ?array
    {
        return [TrackingPlacement::Head]; // Plausible only renders in head
    }

    public function getFieldsTemplate(): ?string
    {
        // A template in your own module's registered template root (see step 2).
        // Return null if your provider needs no config fields, or
        // 'beacon/tracking/_provider-fields/custom' to reuse the raw-snippet field.
        return 'my-module/beacon/_plausible-fields';
    }

    public function render(array $config, TrackingPlacement $placement): string
    {
        $domain = htmlspecialchars((string)($config['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<script defer data-domain="{$domain}" src="https://plausible.io/js/script.js"></script>
HTML;
    }
}
```

Return `null` from `getFixedPlacements()` if the editor should be able to pick where the snippet lands. Return a non-empty list to lock placement to the values you support.

### 2. The CP form template

On the edit form, Beacon includes the template returned by your provider's `getFieldsTemplate()`, with `script` and `provider` in scope. Three ways to satisfy it:

- **Ship your own template** (shown here): register a CP template root from your module (`View::EVENT_REGISTER_CP_TEMPLATE_ROOTS`, e.g. mapping `my-module` → your module's `templates/` dir) and return a path under it.
- **Reuse Beacon's raw-snippet field:** return `'beacon/tracking/_provider-fields/custom'` — a single HTML textarea, no template of your own needed.
- **No config fields:** return `null`; the form renders no Configuration section.

A missing template is ignored rather than fatal, so a typo degrades to "no fields" instead of a 500. Model your template on the bundled ones — copy `src/templates/tracking/_provider-fields/ga4.twig` as a starting point:

```twig
{% import '_includes/forms.twig' as forms %}
{{ forms.textField({
    label: 'Domain'|t('beacon'),
    name: 'config[domain]',
    value: script.config.domain ?? '',
    placeholder: 'example.com',
    required: true,
    errors: script.getErrors('config.domain'),
    first: true,
}) }}
```

Field names **must** be namespaced under `config[…]` — that's how `TrackingService` persists them.

### 3. Register the provider

```php
// in your module's init()
use anvildev\beacon\events\RegisterTrackingProvidersEvent;
use anvildev\beacon\services\TrackingProviderRegistry;
use yii\base\Event;
use mymodule\beacon\PlausibleProvider;

Event::on(
    TrackingProviderRegistry::class,
    TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS,
    static function (RegisterTrackingProvidersEvent $event): void {
        $event->providers[] = new PlausibleProvider();
    }
);
```

That's the full integration. The new provider shows up in the **+ New tracking script** dropdown at `/admin/beacon/tracking`.

## Caching and Project Config

- Each rendered script is cached per `(site, environment, placement)` under the tag `beacon_tracking_scripts`.
- Saving / disabling / reordering scripts in the CP automatically flushes that tag via Project Config listeners (see `TrackingService::handleChangedScript`).
- The cache is **cross-request**, backed by Yii cache with a `TagDependency` keyed on `beacon_tracking_scripts`. Project Config changes invalidate the tag; there's no stale-while-revalidate.

If your provider's `render()` depends on per-request data (UTM params, consent state), prefix the output with a `<script>` block that reads from `window.dataLayer` or a global JS state. Don't try to bypass the cache; you'll fight Beacon's render pipeline.

## Per-environment + per-site behaviour

Beacon's settings model already gates rendering on:

- **Environment** — `development` / `staging` / `production` checkboxes per script.
- **Site overrides** — shallow merge per-site over the default config.

Your provider doesn't need to handle this. By the time `render()` is called, the active site + environment have already been resolved and the merged `config` is passed in.

## Validation rules to bake in

When your `validateConfig()` returns a non-empty array, the CP rejects the save and re-renders the form with errors. Patterns from the built-ins:

| Provider | Validation |
|---|---|
| GA4 | `measurementId` matches `^G-[A-Z0-9]{4,}$` |
| GTM | `containerId` matches `^GTM-[A-Z0-9]+$` |
| Facebook Pixel | `pixelId` is numeric |
| Matomo | `matomoUrl` is `https://…`, `siteId` is positive int |

Echoing untrusted strings into JS context (Custom provider does this on purpose) carries XSS risk. Always wrap user-controlled values with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` inside `render()`.

## Where Twig calls land in your provider

`craft.beacon.head()` resolves to:

```
TrackingService::renderPlacementWithEnv($siteId, 'head', $env)
  → for each enabled script whose `provider` handle matches `getHandle()`:
    → $provider->render($mergedConfig, TrackingPlacement::Head)
  → concatenate, cache, return as raw markup
```

`craft.beacon.trackingFor('head')` is the same path; both invoke `renderPlacement` / `renderPlacementWithEnv` with the placement-keyed cache. Sites that render `head()` and `trackingFor('head')` in the same template will hit the same cached entry, so the cost is one render, not two.

## Removing the built-ins for a deployment

If you ship a managed product where editors should only see your provider, replace the registration:

```php
Event::on(
    TrackingProviderRegistry::class,
    TrackingProviderRegistry::EVENT_REGISTER_PROVIDERS,
    static function (RegisterTrackingProvidersEvent $event): void {
        $event->providers = [new PlausibleProvider()]; // overwrite, don't append
    }
);
```

Note that **existing scripts in the DB referencing removed providers will surface as broken** on the CP index. Beacon does not auto-prune them. Run a migration that deletes rows with `provider IN ('ga4', 'gtm', …)` if you need to scrub them.

## Related

- [Extensibility cookbook](EXTENSIBILITY_COOKBOOK.md) — meta/schema events
