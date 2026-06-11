<?php

namespace anvildev\beacon\console\controllers;

use anvildev\beacon\console\SiteHandleResolverTrait;
use anvildev\beacon\controllers\AdsTxtController;
use anvildev\beacon\controllers\HumansTxtController;
use anvildev\beacon\controllers\LlmsTxtController;
use anvildev\beacon\controllers\RobotsController;
use anvildev\beacon\controllers\SitemapController;
use anvildev\beacon\enums\RenderCacheType;
use anvildev\beacon\Plugin;
use Craft;
use craft\console\Controller;
use craft\models\Site;
use Throwable;
use yii\console\ExitCode;
use yii\web\NotFoundHttpException;

class CacheController extends Controller
{
    use SiteHandleResolverTrait;

    public ?string $site = null;
    public ?string $type = null;

    /**
     * @return list<string>
     */
    public function options($actionID): array
    {
        return ['site', 'type'];
    }

    /**
     * Flush the Beacon render cache. Scope with `--site=<handle>` and
     * `--type=<sitemap|llms-txt|humans|ads>`; omit both to flush everything.
     */
    public function actionFlush(): int
    {
        if ($this->unknownSiteHandle()) {
            return ExitCode::CONFIG;
        }
        Plugin::$plugin->renderCache->flush(
            $this->resolveSiteId(),
            $this->type !== null ? RenderCacheType::tryFrom($this->type) : null,
        );
        $this->stdout("Beacon render cache flushed.\n");
        return ExitCode::OK;
    }

    /**
     * Flush the sitemap cache for a site (`--site=<handle>`, default = primary);
     * the next request regenerates it.
     */
    public function actionRegenerateSitemap(): int
    {
        $siteId = $this->resolveSiteIdOrPrimary();
        if ($siteId === null) {
            return ExitCode::CONFIG;
        }
        Plugin::$plugin->renderCache->flush($siteId, RenderCacheType::Sitemap);
        $this->stdout("Sitemap cache flushed for site $siteId. Next request will regenerate.\n");
        return ExitCode::OK;
    }

    /**
     * Invalidate the llms.txt cache for a site (`--site=<handle>`, default =
     * primary); the next request regenerates it.
     */
    public function actionRegenerateLlmsTxt(): int
    {
        $siteId = $this->resolveSiteIdOrPrimary();
        if ($siteId === null) {
            return ExitCode::CONFIG;
        }
        Plugin::$plugin->renderCache->invalidate($siteId, RenderCacheType::LlmsTxt);
        $this->stdout("llms.txt cache invalidated for site $siteId. Next request will regenerate.\n");
        return ExitCode::OK;
    }

    /**
     * Warm every render cache (sitemap, llms.txt, humans.txt, ads.txt) by
     * flushing and re-rendering each public artifact. Scope with
     * `--site=<handle>`; omit it to warm all sites. Disabled-by-design
     * endpoints are skipped, not counted as failures.
     */
    public function actionRegenerateAll(): int
    {
        $sites = $this->resolveSitesOrAll();
        if ($sites === []) {
            $this->stderr("No sites found for warmup.\n");
            return ExitCode::CONFIG;
        }

        $succeeded = 0;
        $failed = 0;
        foreach ($sites as $site) {
            try {
                $this->warmSiteCaches($site);
                $succeeded++;
            } catch (Throwable $e) {
                $failed++;
                Craft::error(
                    sprintf('Beacon cache warmup failed for site %s: %s', $site->handle, $e->getMessage()),
                    'beacon',
                );
                $this->stderr("Warmup failed for site $site->handle: {$e->getMessage()}\n");
            }
        }

        $suffix = $failed > 0 ? ", $failed failed" : '';
        $this->stdout("Beacon caches regenerated for $succeeded site(s){$suffix}.\n");
        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function warmSiteCaches(Site $site): void
    {
        $app = Craft::$app;
        $app->getSites()->setCurrentSite($site);

        $renderCache = Plugin::$plugin->renderCache;
        $renderCache->flush($site->id, RenderCacheType::Sitemap);
        $renderCache->invalidate($site->id, RenderCacheType::LlmsTxt);
        $renderCache->invalidate($site->id, RenderCacheType::Humans);
        $renderCache->invalidate($site->id, RenderCacheType::Ads);

        foreach ([
            [SitemapController::class, 'beacon-sitemap'],
            [LlmsTxtController::class, 'beacon-llms'],
            [RobotsController::class, 'beacon-robots'],
            [HumansTxtController::class, 'beacon-humans'],
            [AdsTxtController::class, 'beacon-ads'],
        ] as [$cls, $id]) {
            $this->warmIgnoring404(static fn() => (new $cls($id, $app))->actionIndex());
        }
    }

    /**
     * Disabled-by-design endpoints (e.g. humans.txt off on this site) throw
     * NotFoundHttpException — that's not a warmup failure, just nothing to cache.
     *
     * @param callable(): mixed $invoke
     */
    private function warmIgnoring404(callable $invoke): void
    {
        try {
            $invoke();
        } catch (NotFoundHttpException) {
        }
    }
}
