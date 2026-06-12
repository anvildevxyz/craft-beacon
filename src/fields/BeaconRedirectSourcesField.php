<?php

namespace anvildev\beacon\fields;

use anvildev\beacon\helpers\Strings;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;

/**
 * Tag-input field that lets editors attach legacy URIs to an element. On
 * save, Beacon writes one redirect row per URI pointing at the element's
 * current URI (status 301, type exact, source `manual-element`). The
 * field stores nothing in the element's content table — the rows in
 * `beacon_redirects` are the canonical state, so the field stays in sync
 * even if the redirect is deleted directly from the Redirects list.
 *
 * Lifecycle:
 *  - `normalizeValue`        — read the current URIs from beacon_redirects
 *  - `serializeValue`        — return empty string (nothing to persist in
 *                               the elements_sites content row)
 *  - `afterElementSave`      — diff submitted URIs against stored, insert
 *                               new, delete removed; if the element URI
 *                               itself changed, retarget surviving rows
 */
class BeaconRedirectSourcesField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('beacon', 'fields.redirectSources.redirect.sources');
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    public function getContentColumnType(): string
    {
        // The field owns rows in beacon_redirects, not the content column.
        // A 1-byte column satisfies Craft's "field has a value" expectation.
        return 'string(1)';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            return $this->cleanList($value);
        }
        if (is_string($value) && $value !== '' && $value !== '1') {
            $decoded = Json::decodeIfJson($value);
            if (is_array($decoded)) {
                return $this->cleanList($decoded);
            }
        }
        if ($element === null || !$element->id) {
            return [];
        }
        return Plugin::$plugin->redirects->sourcesForElement(
            (int) $element->id,
            (int) ($element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id),
        );
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return '1';
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        return Craft::$app->getView()->renderTemplate('beacon/_fields/redirect-sources-input', [
            'name' => $this->handle,
            'id' => Html::id($this->handle),
            'values' => is_array($value) ? array_values($value) : [],
        ]);
    }

    /**
     * @return list<array{0:list<string>,1:string}>
     */
    public function getElementValidationRules(): array
    {
        return [
            [[(string) $this->handle], 'validateSourceList'],
        ];
    }

    public function validateSourceList(ElementInterface $element): void
    {
        $raw = $element->getFieldValue($this->handle);
        foreach (is_array($raw) ? $raw : [] as $uri) {
            $uri = is_string($uri) ? trim($uri) : '';
            if ($uri === '') {
                continue;
            }
            if (mb_strlen($uri) > 255) {
                $element->addError($this->handle, Craft::t('beacon', 'fields.redirectSources.exceeds.255.characters', ['uri' => $uri]));
                continue;
            }
            if (Strings::containsLineBreaks($uri)) {
                $element->addError($this->handle, Craft::t('beacon', 'fields.redirectSources.contains.invalid.line.breaks', ['uri' => $uri]));
                continue;
            }
            if ($uri[0] !== '/') {
                $element->addError($this->handle, Craft::t('beacon', 'fields.redirectSources.must.start.forward.slash', ['uri' => $uri]));
            }
            if (str_starts_with($uri, '//')) {
                $element->addError($this->handle, Craft::t('beacon', 'fields.redirectSources.must.not.protocol.relative', ['uri' => $uri]));
            }
        }
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        parent::afterElementSave($element, $isNew);

        // Skip drafts/revisions — the canonical save owns the redirect rows.
        if ($element->getIsRevision() || $element->getIsDraft()) {
            return;
        }
        $elementId = (int) $element->id;
        $elementSiteId = (int) $element->siteId;
        if ($elementId <= 0 || $elementSiteId <= 0) {
            return;
        }
        $targetUri = $element->getUrl();
        if ($targetUri === null) {
            // No URL (e.g. headless entry) — clear any orphaned rows.
            Plugin::$plugin->redirects->syncElementSources($elementId, $elementSiteId, '', []);
            return;
        }
        // Domain-agnostic relative path, matching auto-slug redirect behavior.
        $relativeTarget = '/' . ltrim(parse_url($targetUri, PHP_URL_PATH) ?: '', '/');

        $raw = $element->getFieldValue($this->handle);
        $sources = is_array($raw) ? $this->cleanList($raw) : [];

        Plugin::$plugin->redirects->retargetElementSources($elementId, $elementSiteId, $relativeTarget);
        Plugin::$plugin->redirects->syncElementSources($elementId, $elementSiteId, $relativeTarget, $sources);
    }

    /**
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $filtered = array_filter(
            array_map(fn($v) => is_string($v) ? trim($v) : '', $values),
            fn($v) => $v !== '',
        );
        return array_values(array_unique($filtered));
    }
}
