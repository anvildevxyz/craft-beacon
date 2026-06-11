<?php

namespace anvildev\beacon\web;

use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\MarkdownResponse;
use anvildev\beacon\helpers\RawResponse;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\integrations\CommerceIntegration;
use anvildev\beacon\models\Settings;
use anvildev\beacon\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\Application as WebApplication;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\base\Module;
use yii\web\TooManyRequestsHttpException;

final class GeoMarkdownNegotiator
{
    public static function attach(): void
    {
        Event::on(WebApplication::class, Module::EVENT_BEFORE_ACTION, [self::class, 'maybeMarkdown']);
    }

    /**
     * Two trigger paths:
     *
     * - `geoMarkdownNegotiateAcceptHeader` is on AND the client sends `Accept: text/markdown`.
     * - `geoMarkdownAutoServeBots` is on AND the User-Agent matches a known AI bot
     *   from {@see \anvildev\beacon\services\BotRegistry}.
     *
     * The bot path adds `User-Agent` to `Vary` so caches don't conflate bot vs.
     * human responses for the same URL. Both paths share the same lookup +
     * write-through logic against {@see \anvildev\beacon\services\GeoMarkdownStore}.
     *
     * @param ActionEvent<\yii\base\Action<\craft\web\Controller>> $event
     */
    public static function maybeMarkdown(ActionEvent $event): void
    {
        if (!$event->isValid) {
            return;
        }

        $plugin = Plugin::$plugin;
        $app = Craft::$app;
        $req = Http::request();
        if (!$req->getIsSiteRequest() || (!$req->getIsGet() && !$req->getIsHead())) {
            return;
        }

        $trigger = self::resolveTrigger($plugin->settings->get());
        if ($trigger === null) {
            return;
        }
        self::debug(sprintf('GEO negotiation accepted via %s', $trigger));

        try {
            $plugin->geoExportThrottle->enforce($trigger === 'bot' ? 'geo_export_bot' : 'geo_export_accept');
        } catch (TooManyRequestsHttpException) {
            Craft::warning('GEO negotiation throttled (429)', 'beacon');
            $resp = Http::response();
            $resp->statusCode = 429;
            $resp->content = 'Rate limit exceeded.';
            $app->end(0, $resp);
        }

        $element = $app->getUrlManager()->getMatchedElement();
        if (!$element instanceof ElementInterface) {
            self::debug('GEO negotiation skipped: no matched element');
            return;
        }
        if (!$element instanceof Entry && !self::isCommerceProductSupported($element)) {
            self::debug('GEO negotiation skipped: matched element type is not exportable');
            return;
        }
        if (
            $element->getIsDraft()
            || $element->getIsUnpublishedDraft()
            || $element->getIsRevision()
            || $element->getStatus() !== 'live'
        ) {
            return;
        }
        if (SeoFieldReader::isNoIndexFor($element)) {
            self::debug('GEO negotiation skipped: element is noindex');
            return;
        }

        $siteId = (int) $element->siteId;
        $elementId = (int) $element->id;
        $store = $plugin->geoMarkdownStore;
        $row = $store->find($siteId, $elementId);
        if ($row !== null && is_string($row['markdown']) && $row['markdown'] !== '') {
            $markdown = $row['markdown'];
            $store->touchRequested($siteId, $elementId);
        } else {
            $markdown = $plugin->geoMarkdownExport->exportElement($element);
            if ($markdown === null) {
                self::debug('GEO negotiation skipped: element not exportable');
                return;
            }
            $store->put($siteId, $elementId, $markdown);
        }

        Http::response()->clear();
        $response = RawResponse::build(
            'text/markdown; charset=UTF-8',
            $markdown,
            120,
            $element->dateUpdated,
            cacheTags: [
                'beacon-geo-md',
                "beacon-site-{$siteId}",
                "beacon-entry-{$elementId}",
            ],
        );
        $response->statusCode = 200;
        $canonical = $element->getUrl();
        MarkdownResponse::applyHeaders(
            $response,
            is_string($canonical) ? $canonical : null,
            includeUserAgentInVary: $trigger === 'bot',
        );

        $app->end(0, $response);
    }

    /**
     * Returns 'accept', 'bot', or null. Accept-header negotiation wins when
     * both paths would trigger — it's the more explicit signal.
     */
    private static function resolveTrigger(Settings $settings): ?string
    {
        // Master kill-switch: with GEO Markdown off, the negotiation paths must
        // not serve previously-stored markdown either — the `.md` route and
        // fresh exports are gated elsewhere, and serving stale store rows here
        // would keep feeding AI bots after the operator opted out.
        if (!$settings->geoMarkdownEnabled) {
            return null;
        }
        if ($settings->geoMarkdownNegotiateAcceptHeader && self::clientPrefersMarkdown()) {
            return 'accept';
        }
        if ($settings->geoMarkdownAutoServeBots && self::userAgentIsKnownBot()) {
            return 'bot';
        }
        return null;
    }

    private static function clientPrefersMarkdown(): bool
    {
        foreach (Http::request()->acceptableContentTypes as $type => $_) {
            $l = strtolower((string) $type);
            if (str_contains($l, 'markdown') || str_contains($l, 'text/md')) {
                return true;
            }
        }
        return false;
    }

    private static function userAgentIsKnownBot(): bool
    {
        $ua = Http::request()->getUserAgent() ?? '';
        return $ua !== '' && Plugin::$plugin->botRegistry->match($ua) !== null;
    }

    private static function isCommerceProductSupported(ElementInterface $element): bool
    {
        /** @phpstan-ignore-next-line — Commerce is an optional dependency */
        return CommerceIntegration::isMarkdownEligible() && $element instanceof \craft\commerce\elements\Product;
    }

    /**
     * Per-request negotiation diagnostics. These fire on every triggered site
     * request, so they're gated behind `BEACON_META_DEBUG=1` or devMode rather
     * than relying on the prod log threshold suppressing `info`.
     */
    private static function debug(string $message): void
    {
        if (getenv('BEACON_META_DEBUG') === '1' || Craft::$app->getConfig()->getGeneral()->devMode) {
            Craft::info($message, 'beacon');
        }
    }
}
