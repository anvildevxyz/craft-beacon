<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\RobotsSettings;
use anvildev\beacon\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * @phpstan-import-type UserAgentRule from \anvildev\beacon\models\RobotsSettings
 */
class RobotsSettingsController extends Controller
{
    use BeaconCpPermissionTrait;
    use SiteScopedCpControllerTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_CRAWLERS;

    /**
     * Renders the robots.txt settings form for the current (or selected) site.
     */
    public function actionIndex(): Response
    {
        $site = $this->resolveSite();
        $settings = Plugin::$plugin->siteSettings->getRobots($site->id);
        return $this->renderTemplate('beacon/crawlers/index', [
            'selectedCrawlerTab' => 'robots',
            'site' => $site,
            'settings' => $settings,
            'userAgentRulesText' => $this->renderUserAgentRulesText($settings->userAgentRules),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Parses the posted user-agent rules text, persists the robots.txt settings
     * for a site, and redirects back to the settings screen.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $siteId = $this->resolveSiteIdFromPost();
        $rawText = $request->getBodyParam('userAgentRulesText', '');

        Plugin::$plugin->siteSettings->saveRobots(new RobotsSettings(
            siteId: $siteId,
            sitemapUrl: Strings::trimToNull($request->getBodyParam('sitemapUrl')) ?? 'auto',
            userAgentRules: $this->parseUserAgentRulesText(is_string($rawText) ? $rawText : ''),
        ));

        Craft::$app->getSession()->setNotice(Craft::t('beacon', 'Robots.txt settings saved.'));
        return $this->redirectAfterSiteSave('beacon/crawlers/robots', $siteId);
    }

    /**
     * @return list<UserAgentRule>
     */
    private function parseUserAgentRulesText(string $text): array
    {
        $rules = [];
        $current = null;

        /** @var callable(array{userAgent: string, allow: list<string>, disallow: list<string>}|null): void $flush */
        $flush = static function(?array $c) use (&$rules): void {
            if ($c === null) {
                return;
            }
            $rule = ['userAgent' => $c['userAgent']];
            if ($c['allow'] !== []) {
                $rule['allow'] = $c['allow'];
            }
            if ($c['disallow'] !== []) {
                $rule['disallow'] = $c['disallow'];
            }
            $rules[] = $rule;
        };

        foreach (Strings::splitLines($text) as $line) {
            if ($line[0] === '#') {
                continue;
            }
            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $m)) {
                $flush($current);
                $current = ['userAgent' => trim($m[1]), 'allow' => [], 'disallow' => []];
                continue;
            }
            if ($current !== null && preg_match('/^(allow|disallow)\s*:\s*(.+)$/i', $line, $m)) {
                $key = strtolower($m[1]) === 'allow' ? 'allow' : 'disallow';
                $current[$key][] = trim($m[2]);
            }
        }
        $flush($current);

        return $rules;
    }

    /**
     * @param list<UserAgentRule> $rules
     */
    private function renderUserAgentRulesText(array $rules): string
    {
        $lines = [];
        foreach ($rules as $rule) {
            $lines[] = 'User-agent: ' . $rule['userAgent'];
            foreach ($rule['allow'] ?? [] as $path) {
                $lines[] = 'Allow: ' . $path;
            }
            foreach ($rule['disallow'] ?? [] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines));
    }
}
