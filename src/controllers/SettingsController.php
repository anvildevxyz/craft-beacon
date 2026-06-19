<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\elements\AuthorElement;
use anvildev\beacon\helpers\AiUsagePolicy;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\IdentityTypes;
use anvildev\beacon\helpers\RobotsDirectives;
use anvildev\beacon\helpers\SocialPlatforms;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use Craft;
use craft\queue\jobs\UpdateElementSlugsAndUris;
use craft\web\Controller;
use yii\web\Response;

class SettingsController extends Controller
{
    use AssetSelectorTrait;
    use BeaconCpPermissionTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SETTINGS;

    /** @var array<string,bool> */
    private const ALLOWED_TABS = [
        'general' => true, 'robots' => true, 'content' => true,
        'organization' => true, 'social' => true,
        'authors' => true, 'geo' => true, 'ai' => true, 'mcp' => true,
    ];

    /**
     * Renders the settings screen on the general tab.
     */
    public function actionIndex(): Response
    {
        return $this->renderSettingsTemplate('general');
    }

    /**
     * Renders the settings screen on a specific tab, falling back to the general
     * tab when the requested tab is unknown.
     *
     * @param string $tab the settings tab handle from the route
     */
    public function actionSection(string $tab): Response
    {
        return $this->renderSettingsTemplate($this->normalizeTab($tab));
    }

    private function normalizeTab(string|int|float|bool|null $raw): string
    {
        $tab = strtolower(trim((string) $raw));
        return isset(self::ALLOWED_TABS[$tab]) ? $tab : 'general';
    }

    private function renderSettingsTemplate(string $selectedSettingsSubnavItem): Response
    {
        $settings = Plugin::$plugin->settings->get();
        return $this->renderTemplate('beacon/settings/index', [
            'selectedSettingsSubnavItem' => $selectedSettingsSubnavItem,
            'identityTypeOptions' => IdentityTypes::all(),
            'settings' => $settings,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'renderCacheRows' => (int) Craft::$app->getDb()
                ->createCommand('SELECT COUNT(*) FROM {{%beacon_render_cache}}')
                ->queryScalar(),
            'robotsDirectiveDefs' => RobotsDirectives::definitions(),
            'robotsEnabled' => RobotsDirectives::resolveEnabledMap($settings->robotsDirectivesEnabled),
            'beaconSocialPlatforms' => SocialPlatforms::all(),
        ]);
    }

    /**
     * Applies the posted settings to the plugin settings model, saves it, and
     * redirects back to the tab the form was submitted from.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $params = Http::request()->getBodyParams();

        $patched = clone Plugin::$plugin->settings->get();

        $apply = static function(string $key, callable $fn) use ($params, $patched): void {
            if (array_key_exists($key, $params)) {
                $fn($patched, $params[$key]);
            }
        };

        $bool = static fn(string $k) => static fn(Settings $s, $v) => $s->$k = (bool) $v;
        $trim = static fn(string $k, string $default) => static fn(Settings $s, $v) => $s->$k = trim((string) $v) ?: $default;
        $trimNullable = static fn(string $k) => static fn(Settings $s, $v) => $s->$k = ((string) $v) !== '' ? (string) $v : null;

        $apply('titleTemplate', $trim('titleTemplate', '{title}'));
        $apply('descriptionTemplate', static fn(Settings $s, $v) => $s->descriptionTemplate = trim((string) $v));
        $apply('organizationName', $trimNullable('organizationName'));
        $apply('organizationLogoAssetId', fn(Settings $s, $v) => $s->organizationLogoAssetId = $this->viewableAssetIdFromSelector($v));
        $apply('organizationImageAssetId', fn(Settings $s, $v) => $s->organizationImageAssetId = $this->viewableAssetIdFromSelector($v));
        $apply('authorPagesEnabled', $bool('authorPagesEnabled'));
        $apply('authorPagesUriPrefix', static fn(Settings $s, $v) => $s->authorPagesUriPrefix = trim((string) $v, "/ \t\n\r\0\x0B") ?: 'authors');
        $apply('socialProfiles', static function(Settings $s, $v): void {
            $map = [];
            if (is_array($v)) {
                foreach (SocialPlatforms::keys() as $key) {
                    $url = $v[$key] ?? null;
                    if (is_string($url) && ($trimmed = trim($url)) !== '') {
                        $map[$key] = $trimmed;
                    }
                }
            }
            $s->socialProfiles = $map;
        });
        $apply('identityType', static function(Settings $s, $v): void {
            $s->identityType = IdentityTypes::normalize(trim((string) $v));
        });
        $apply('identityAdvanced', fn(Settings $s, $v) => $s->identityAdvanced = $this->parseIdentityAdvanced((array) $v));
        $apply('sectionSeoDefaults', fn(Settings $s, $v) => $s->sectionSeoDefaults = $this->parseSectionSeoDefaults((array) $v));
        // socialImageTransform is a developer concern — set it via config/beacon.php, not the CP.
        $apply('defaultSocialImageId', fn(Settings $s, $v) => $s->defaultSocialImageId = $this->viewableAssetIdFromSelector($v));
        $apply('geoMarkdownEnabled', $bool('geoMarkdownEnabled'));
        $apply('geoMarkdownNegotiateAcceptHeader', $bool('geoMarkdownNegotiateAcceptHeader'));
        $apply('geoMarkdownMdSuffixEnabled', $bool('geoMarkdownMdSuffixEnabled'));
        $apply('geoMarkdownExcerptFallbackToDescription', $bool('geoMarkdownExcerptFallbackToDescription'));
        $apply('geoMarkdownAutoServeBots', $bool('geoMarkdownAutoServeBots'));
        $apply('geoProvenanceSchemaEnabled', $bool('geoProvenanceSchemaEnabled'));
        $apply('geoScoreEnabled', $bool('geoScoreEnabled'));
        // AI content generation. The API key is write-once-ish: a blank submit
        // keeps the stored key so editors don't have to re-paste it on every save.
        $apply('aiEnabled', $bool('aiEnabled'));
        $apply('aiProvider', static fn(Settings $s, $v) => $s->aiProvider = in_array((string) $v, ['anthropic', 'openai'], true) ? (string) $v : 'anthropic');
        $apply('aiModel', static fn(Settings $s, $v) => $s->aiModel = trim((string) $v));
        $apply('aiApiKey', static function(Settings $s, $v): void {
            $v = trim((string) $v);
            if ($v !== '') {
                $s->aiApiKey = $v;
            }
        });
        $apply('aiBaseUrl', static fn(Settings $s, $v) => $s->aiBaseUrl = trim((string) $v) !== '' ? trim((string) $v) : null);
        $apply('aiUsagePolicy', static fn(Settings $s, $v) => $s->aiUsagePolicy = AiUsagePolicy::normalize((string) $v));
        $apply('aiUsagePolicyUrl', $trimNullable('aiUsagePolicyUrl'));
        $apply('mcpEnabled', $bool('mcpEnabled'));
        // Developer-level GEO knobs (render mode, excluded CSS classes, fact-density
        // target, authority-domain overrides) are set via config/beacon.php, not here.
        $apply('robotsDirectivesEnabled', fn(Settings $s, $v) => $s->robotsDirectivesEnabled = $this->parseRobotsDirectivesEnabled($v));

        $before = Plugin::$plugin->settings->get();
        $authorRoutingChanged = $before->authorPagesEnabled !== $patched->authorPagesEnabled
            || $before->authorPagesUriPrefix !== $patched->authorPagesUriPrefix;

        Plugin::$plugin->settings->save($patched);

        // Author URIs are stamped at element-save time, so an enable/disable or
        // prefix change leaves every existing author at its old (or NULL) URI
        // until re-saved. Mirror Craft's section behavior: queue a slug/URI
        // refresh per site so the public routes follow the setting immediately.
        if ($authorRoutingChanged) {
            $queue = Craft::$app->getQueue();
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $queue->push(new UpdateElementSlugsAndUris([
                    'elementType' => AuthorElement::class,
                    'siteId' => (int) $site->id,
                    'updateOtherSites' => false,
                    'updateDescendants' => false,
                ]));
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'flash.settings.settings.saved'));
        return $this->redirect('beacon/settings/' . $this->normalizeTab($params['tab'] ?? null));
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,array<string,string>>
     */
    private function parseSectionSeoDefaults(array $raw): array
    {
        $knownSectionHandles = array_flip(array_column(
            Craft::$app->getEntries()->getAllSections(),
            'handle',
        ));

        $out = [];
        foreach ($raw as $sectionHandle => $row) {
            if (!is_string($sectionHandle) || !is_array($row) || !isset($knownSectionHandles[$sectionHandle])) {
                continue;
            }
            $titleTemplate = trim((string) ($row['titleTemplate'] ?? ''));
            $descriptionTemplate = trim((string) ($row['descriptionTemplate'] ?? ''));
            $aiUsage = AiUsagePolicy::normalizeOrInherit(is_string($row['aiUsage'] ?? null) ? $row['aiUsage'] : null);
            if ($titleTemplate === '' && $descriptionTemplate === '' && $aiUsage === null) {
                continue;
            }
            $out[$sectionHandle] = [
                'titleTemplate' => $titleTemplate,
                'descriptionTemplate' => $descriptionTemplate,
            ];
            if ($aiUsage !== null) {
                $out[$sectionHandle]['aiUsage'] = $aiUsage;
            }
        }
        return $out;
    }

    /**
     * Normalizes the directive checkboxes posted from the settings form into
     * a `key => bool` map covering every known directive. Unknown keys are
     * dropped; missing keys default to `false`.
     *
     * @param array<mixed, mixed>|string|null $raw
     * @return array<string,bool>
     */
    private function parseRobotsDirectivesEnabled(array|string|null $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (RobotsDirectives::keys() as $key) {
            $out[$key] = !empty($raw[$key]);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function parseIdentityAdvanced(array $raw): array
    {
        $out = [];
        foreach ([
            'alternateName', 'legalName', 'description', 'email', 'telephone',
            'streetAddress', 'addressLocality', 'addressRegion', 'postalCode',
            'addressCountry', 'geoLatitude', 'geoLongitude', 'foundingDate',
            'foundingLocation', 'contactType', 'contactEmail',
            'contactTelephone', 'givenName', 'familyName', 'jobTitle',
            'birthPlace', 'taxID', 'naics', 'duns', 'iso6523Code',
        ] as $key) {
            $value = trim((string) ($raw[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        foreach (['knowsAbout', 'knowsLanguage', 'founder'] as $listKey) {
            $list = Strings::splitLines((string) ($raw[$listKey] ?? ''));
            if ($list !== []) {
                $out[$listKey] = $list;
            }
        }
        return $out;
    }
}
