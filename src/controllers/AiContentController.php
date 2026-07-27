<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\Plugin;
use anvildev\beacon\services\ai\AiException;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * AJAX endpoints for the SEO field's AI "Generate" affordances.
 *
 * Every action requires POST + JSON, the `beacon:useAiGeneration` permission,
 * a configured AI provider, and view access to the target element. A per-site
 * daily call cap bounds runaway cost. Nothing here writes to an element — the
 * generated value is returned for the editor to accept or discard.
 *
 * Routed as `actions/beacon/ai-content/<action>` via the plugin namespace.
 */
class AiContentController extends Controller
{
    use PostJsonTrait;

    protected array|bool|int $allowAnonymous = false;

    /** Hard ceiling on generation calls per site per UTC day. */
    private const DAILY_CAP = 200;

    public function actionGenerateTitle(): Response
    {
        $entry = $this->prepareForEntry();
        return $this->generate(fn() => ['value' => Plugin::$plugin->aiContent->generateTitle($entry)]);
    }

    public function actionGenerateDescription(): Response
    {
        $entry = $this->prepareForEntry();
        return $this->generate(fn() => ['value' => Plugin::$plugin->aiContent->generateDescription($entry)]);
    }

    public function actionGenerateSummary(): Response
    {
        $entry = $this->prepareForEntry();
        return $this->generate(fn() => ['value' => Plugin::$plugin->aiContent->generateSummary($entry)]);
    }

    public function actionGenerateFaq(): Response
    {
        $entry = $this->prepareForEntry();
        return $this->generate(function() use ($entry) {
            $faq = Plugin::$plugin->aiContent->generateFaq($entry);
            return [
                'faq' => $faq,
                'schema' => Plugin::$plugin->aiContent->faqSchema($faq),
            ];
        });
    }

    public function actionGenerateAltText(): Response
    {
        $this->commonGuards();
        $request = Http::request();
        $assetId = (int) $request->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->one();
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException(Craft::t('beacon', 'error.seoField.entry.not.found', ['id' => $assetId]));
        }
        $this->requireCanView($asset);

        $entryId = (int) ($request->getBodyParam('entryId') ?: 0);
        $entry = $entryId > 0 ? Entry::find()->id($entryId)->status(null)->drafts(null)->provisionalDrafts(null)->siteId('*')->one() : null;

        return $this->generate(fn() => [
            'value' => Plugin::$plugin->aiContent->generateAltText($asset, $entry instanceof Entry ? $entry : null),
        ]);
    }

    /**
     * Run a generation closure, translating a provider AiException into a JSON
     * error response (HTTP 502) instead of letting it surface as a fatal 500.
     * The front-end reads the `error` key to surface the provider's reason.
     *
     * @param callable(): array<string, mixed> $fn
     */
    private function generate(callable $fn): Response
    {
        try {
            return $this->asJson($fn());
        } catch (AiException $e) {
            Craft::warning('Beacon AI generation failed: ' . $e->getMessage(), __METHOD__);
            $response = $this->asJson(['error' => $e->getMessage()]);
            $response->setStatusCode(502);
            return $response;
        }
    }

    /**
     * Shared guards + entry resolution for the text-generation actions.
     *
     * @throws BadRequestHttpException|ForbiddenHttpException|NotFoundHttpException|TooManyRequestsHttpException
     */
    private function prepareForEntry(): Entry
    {
        $this->commonGuards();
        $entryId = (int) Http::request()->getRequiredBodyParam('entryId');
        $entry = Entry::find()
            ->id($entryId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->siteId('*')
            ->one();
        if (!$entry instanceof Entry) {
            throw new NotFoundHttpException(Craft::t('beacon', 'error.seoField.entry.not.found', ['id' => $entryId]));
        }
        $this->requireCanView($entry);
        return $entry;
    }

    /**
     * POST+JSON, permission, configured-provider, and rate-limit checks shared
     * by every action.
     *
     * @throws BadRequestHttpException|ForbiddenHttpException|TooManyRequestsHttpException
     */
    private function commonGuards(): void
    {
        $this->requirePostJson();
        $this->requirePermission(BeaconPermissions::USE_AI_GENERATION);
        if (!Plugin::$plugin->aiClient->isConfigured()) {
            throw new BadRequestHttpException(Craft::t('beacon', 'ai.not.configured'));
        }
        $this->enforceRateLimit();
    }

    /**
     * @throws TooManyRequestsHttpException when the per-site daily cap is hit
     */
    private function enforceRateLimit(): void
    {
        $siteId = (int) (Http::request()->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $cache = Craft::$app->getCache();
        $key = "beacon:ai:rate:{$siteId}:" . gmdate('Ymd');
        $count = (int) $cache->get($key);
        if ($count >= self::DAILY_CAP) {
            throw new TooManyRequestsHttpException(Craft::t('beacon', 'ai.rate.limited'));
        }
        $cache->set($key, $count + 1, 86400);
    }

    /**
     * @throws ForbiddenHttpException when the current user can't view $element
     */
    private function requireCanView(\craft\base\ElementInterface $element): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !Craft::$app->getElements()->canView($element, $user)) {
            throw new ForbiddenHttpException();
        }
    }
}
