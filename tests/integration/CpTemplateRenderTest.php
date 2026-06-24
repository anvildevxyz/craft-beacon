<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\AiVisibilityController;
use anvildev\beacon\controllers\McpTokensController;
use anvildev\beacon\controllers\SettingsController;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\User;
use craft\test\TestCase;
use craft\web\TemplateResponseFormatter;
use yii\web\Response;

/**
 * Invokes each Beacon CP screen's controller action end to end and asserts it
 * renders without throwing. Because it runs the real action (not a re-supplied
 * variable set), it catches the class of bug smoke testing found: a controller
 * that forgets to pass a variable its template references
 * (AiVisibilityController::actionIndex omitted `settings`, producing a Twig
 * RuntimeError 500 the unit suite couldn't see).
 *
 * Add a row to {@see self::cases()} whenever a new CP screen ships.
 *
 * @group requires-craft
 */
class CpTemplateRenderTest extends TestCase
{
    /**
     * @return array<string, array{0:callable():Response}>
     */
    public static function cases(): array
    {
        return [
            'ai-visibility/index' => [
                static fn(): Response => (new AiVisibilityController('ai-visibility', Plugin::getInstance()))->actionIndex(),
            ],
            'mcp-tokens/index' => [
                static fn(): Response => (new McpTokensController('mcp-tokens', Plugin::getInstance()))->actionIndex(),
            ],
            'settings/_tabs/ai' => [
                static fn(): Response => (new SettingsController('settings', Plugin::getInstance()))->actionSection('ai'),
            ],
            'settings/_tabs/mcp' => [
                static fn(): Response => (new SettingsController('settings', Plugin::getInstance()))->actionSection('mcp'),
            ],
            'settings/_tabs/content' => [
                static fn(): Response => (new SettingsController('settings', Plugin::getInstance()))->actionSection('content'),
            ],
        ];
    }

    /**
     * @dataProvider cases
     * @param callable():Response $invoke
     */
    public function testCpScreenRendersWithoutError(callable $invoke): void
    {
        $response = $invoke();

        // Craft's renderTemplate() is lazy — it attaches a behavior carrying the
        // controller's variables and renders at send time. Run the formatter now
        // so the Twig render happens with those exact variables, surfacing any
        // "undefined variable the controller forgot to pass" error.
        (new TemplateResponseFormatter())->format($response);

        $this->assertSame(200, $response->statusCode);
        $this->assertNotEmpty($response->content, 'rendered CP screen must produce output');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // CP screens extend _layouts/cp and use form macros, which expect a CP
        // request with a logged-in admin identity.
        $request = Craft::$app->getRequest();
        $request->setIsConsoleRequest(false);
        if (method_exists($request, 'setIsCpRequest')) {
            $request->setIsCpRequest(true);
        }
        // _layouts/cp emits a CSRF input, which needs a cookie validation key the
        // test env doesn't configure. Supply one so rendering reaches completion.
        if (empty($request->cookieValidationKey)) {
            $request->cookieValidationKey = 'beacon-cp-render-test-cookie-validation-key';
        }
        $admin = User::find()->admin(true)->status(null)->one();
        if ($admin instanceof User) {
            Craft::$app->getUser()->setIdentity($admin);
        }
    }
}
