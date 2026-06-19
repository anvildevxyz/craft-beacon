<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\models\AiCrawlerRule;
use anvildev\beacon\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

class RobotsController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Renders and serves the robots.txt body for the current site, merging the
     * site's user-agent rules with the enabled AI-crawler rules.
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $plugin = Plugin::$plugin;
        $settings = $plugin->siteSettings->getRobots($site->id);

        $aiRules = array_map(
            static fn(AiCrawlerRule $r) => ['bot' => $r->botName, 'allow' => $r->allowPaths, 'disallow' => $r->disallowPaths],
            $plugin->aiCrawlers->getEnabledRules(),
        );

        $globalSettings = $plugin->settings->get();
        $scopes = $plugin->aiUsage->gatherSectionScopes($site->id, $globalSettings->sectionSeoDefaults);
        $contentSignalLines = $plugin->aiUsage->contentSignalLines(
            $globalSettings->aiUsagePolicy,
            $scopes['policies'],
            $scopes['prefixes'],
        );

        return RawResponse::build(
            'text/plain; charset=UTF-8',
            $plugin->robots->render(
                $settings->userAgentRules,
                $aiRules,
                $settings->sitemapUrl === 'auto' ? UrlHelper::siteUrl('sitemap.xml') : $settings->sitemapUrl,
                $contentSignalLines,
            ),
            cacheTags: ['beacon-robots', "beacon-site-{$site->id}"],
        );
    }
}
