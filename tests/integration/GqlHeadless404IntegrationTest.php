<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\gql\mutations\BeaconRedirectMutations;
use anvildev\beacon\gql\queries\BeaconRedirectQueries;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\records\Redirect404LogRecord;
use anvildev\beacon\records\RedirectRecord;
use Craft;
use craft\enums\PropagationMethod;
use craft\test\TestCase;
use GraphQL\Error\UserError;

/**
 * Resolver-level coverage for the headless 404 GraphQL surface (issue #39):
 * `beaconResolveRedirect` (read-only matcher) and the `beaconTrack404`
 * mutation (hit counting + 404 logging). Exercises the resolvers directly
 * against the DB; schema wiring is asserted via the registration methods.
 *
 * @group requires-craft
 */
class GqlHeadless404IntegrationTest extends TestCase
{
    public function testResolveRedirectMatchesGlobWithoutSideEffects(): void
    {
        $site = $this->siteId();
        $element = $this->makeRedirect($site, '/blog/*', '/news/$1', RedirectType::Glob->value);

        $result = BeaconRedirectQueries::resolveRedirectForUri(null, [
            'siteId' => $site,
            // Missing leading slash must be tolerated.
            'uri' => 'blog/hello-world',
        ]);

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('/news/hello-world', $result->resolvedTarget);
        $this->assertSame(301, $result->statusCode);

        // Read-only: the rule's hit counter must be untouched.
        $this->assertSame(0, (int) RedirectRecord::findOne((int) $element->id)->hits);
    }

    public function testResolveRedirectReturnsNullForNoMatchOrEmptyUri(): void
    {
        $site = $this->siteId();

        $this->assertNull(BeaconRedirectQueries::resolveRedirectForUri(null, ['siteId' => $site, 'uri' => '/nope']));
        $this->assertNull(BeaconRedirectQueries::resolveRedirectForUri(null, ['siteId' => $site, 'uri' => '  ']));
    }

    public function testTrack404RecordsRedirectHit(): void
    {
        $site = $this->siteId();
        $element = $this->makeRedirect($site, '/moved', '/target');

        $payload = BeaconRedirectMutations::track404(null, ['siteId' => $site, 'uri' => '/moved']);

        $this->assertInstanceOf(Redirect::class, $payload['redirect']);
        $this->assertSame('/target', $payload['redirect']->resolvedTarget);
        $this->assertFalse($payload['logged']);
        $this->assertSame(1, (int) RedirectRecord::findOne((int) $element->id)->hits);
        // A matched redirect is not a 404 — nothing may hit the log.
        $this->assertNull(Redirect404LogRecord::findOne(['uri' => '/moved']));
    }

    public function testTrack404LogsUnmatchedUriAndAccumulatesHits(): void
    {
        $site = $this->siteId();

        $first = BeaconRedirectMutations::track404(null, [
            'siteId' => $site,
            'uri' => '/gone-page',
            'referer' => 'https://frontend.example/somewhere',
        ]);

        $this->assertNull($first['redirect']);
        $this->assertTrue($first['logged']);

        $row = Redirect404LogRecord::findOne(['siteId' => $site, 'uri' => '/gone-page']);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->hits);
        $this->assertSame('https://frontend.example/somewhere', $row->referer);
        $this->assertFalse((bool) $row->handled);

        $second = BeaconRedirectMutations::track404(null, ['siteId' => $site, 'uri' => '/gone-page']);
        $this->assertTrue($second['logged']);
        $this->assertSame(2, (int) Redirect404LogRecord::findOne(['siteId' => $site, 'uri' => '/gone-page'])->hits);
    }

    public function testTrack404FiltersBotUserAgents(): void
    {
        $site = $this->siteId();

        // The 404 log's bot filter matches the plugin's AI-bot registry
        // (GPTBot & co., same as the native pipeline) — not generic crawlers.
        $payload = BeaconRedirectMutations::track404(null, [
            'siteId' => $site,
            'uri' => '/bot-hit',
            'userAgent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
        ]);

        $this->assertNull($payload['redirect']);
        $this->assertFalse($payload['logged']);
        $this->assertNull(Redirect404LogRecord::findOne(['uri' => '/bot-hit']));
    }

    public function testTrack404RejectsUnknownSite(): void
    {
        $this->expectException(UserError::class);
        BeaconRedirectMutations::track404(null, ['siteId' => 999999, 'uri' => '/x']);
    }

    public function testSchemaRegistrationExposesQueryAndMutation(): void
    {
        $this->assertArrayHasKey('beaconResolveRedirect', BeaconRedirectQueries::getQueries(false));
        $this->assertArrayHasKey('beaconTrack404', BeaconRedirectMutations::getMutations(false));
    }

    private function siteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    private function makeRedirect(int $siteId, string $source, string $target, ?string $type = null): RedirectElement
    {
        $element = new RedirectElement();
        $element->propagationMethod = PropagationMethod::None;
        $element->siteId = $siteId;
        $element->sourceUri = $source;
        $element->targetUri = $target;
        $element->statusCode = 301;
        $element->type = $type ?? RedirectType::Exact->value;
        $element->enabled = true;
        $element->queryStringMode = 'ignore';
        $element->source = 'manual';

        $saved = Craft::$app->getElements()->saveElement($element);
        $this->assertTrue($saved, 'save redirect: ' . json_encode($element->getErrors()));

        return $element;
    }
}
