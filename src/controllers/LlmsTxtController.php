<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\models\Site;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LlmsTxtController extends Controller
{
    use CachedTextResponseTrait;

    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Renders and serves the llms.txt index for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when llms.txt is disabled for the site
     */
    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $settings = Plugin::$plugin->siteSettings->getLlms($site->id);
        if (!$settings->enabled) {
            Craft::info("llms.txt disabled for siteId={$site->id}", 'beacon');
            throw new NotFoundHttpException();
        }

        return $this->cachedTextResponse(
            RenderCacheType::LlmsTxt,
            'text/markdown; charset=UTF-8',
            'beacon-llms',
            function(Site $site) use ($settings): string {
                $trust = array_filter([
                    'policyUrl' => $settings->policyUrl,
                    'licenseUrl' => $settings->licenseUrl,
                    'contactEmail' => $settings->contactEmail,
                    'preferredAttribution' => $settings->preferredAttribution,
                ], static fn($v) => $v !== null && $v !== '');

                return Plugin::$plugin->llmsTxt->render(
                    siteName: (string) ($settings->siteNameOverride ?? $site->name),
                    summary: is_string($settings->summary) ? $settings->summary : null,
                    sections: $this->collectSections($site->id, $settings->sections),
                    trust: $trust,
                );
            },
            1800,
        );
    }

    /**
     * Serves the full llms-full.txt body for the current site.
     *
     * @throws \yii\web\NotFoundHttpException when llms-full.txt is disabled or has no body
     */
    public function actionFull(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $settings = Plugin::$plugin->siteSettings->getLlms($site->id);
        $fullBody = $settings->fullBody ?? '';
        $body = $settings->enabled && trim($fullBody) !== '' ? $fullBody : null;

        if ($body === null) {
            Craft::info("llms-full.txt unavailable for siteId={$site->id}", 'beacon');
            throw new NotFoundHttpException();
        }

        Craft::info("llms-full.txt rendered for siteId={$site->id} bytes=" . strlen($body), 'beacon');
        return RawResponse::build(
            'text/markdown; charset=UTF-8',
            $body,
            cacheTags: ['beacon-llms-full', "beacon-site-{$site->id}"],
        );
    }

    /**
     * @param list<string> $sectionHandles
     * @return array<string, list<array{title:string, url:string, description:?string}>>
     */
    private function collectSections(int $siteId, array $sectionHandles): array
    {
        $result = [];
        foreach ($sectionHandles as $handle) {
            // Skip Commerce/integration placeholder handles (e.g. __products__) — they
            // cause QueryAbortedException in Craft's ElementQuery.
            if (str_starts_with($handle, '__') && str_ends_with($handle, '__')) {
                continue;
            }
            $list = [];
            $entries = Entry::find()
                ->section($handle)
                ->siteId($siteId)
                ->status(Entry::STATUS_LIVE)
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->limit(5000);
            foreach ($entries->each(500) as $entry) {
                assert($entry instanceof Entry);
                $url = SeoFieldReader::indexableUrl($entry);
                if ($url === null) {
                    continue;
                }
                $list[] = [
                    'title' => (string) $entry->title,
                    'url' => $url,
                    'description' => SeoFieldReader::readDescriptionFor($entry),
                ];
            }
            if ($list !== []) {
                $result[$handle] = $list;
            }
        }
        return $result;
    }
}
