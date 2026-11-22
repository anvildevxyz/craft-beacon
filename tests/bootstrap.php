<?php

require __DIR__ . '/../vendor/autoload.php';

// Yii.php is not autoloaded (it lives in the global namespace and the file
// itself sets up the framework's autoloader + DI container). Load it once
// here so that yii\base\Model and friends can resolve Yii::createObject /
// Yii::t / Yii::debug at validation time. Crucially, this does NOT set
// Yii::$app — so test classes don't share a stale Application instance.
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// Craft.php is also a global-namespace bootstrap file (Craft extends Yii) and
// is not in the composer classmap. Tracking-script providers and other plugin
// code reference `Craft::t(...)` in their constructors / static method tables;
// without the class loaded, mere reflection / call resolution explodes with
// "Class 'Craft' not found" even though the methods inherit safely from Yii.
// Loading the file does NOT initialize Craft::$app — same isolation guarantee
// as the Yii bootstrap above.
//
// Craft 6 moved the global \Craft facade out of craftcms/cms entirely, into
// the yii2-adapter package instead (the original path simply doesn't exist
// there). Craft 5 still has it at the original path.
$craftFacade = __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';
if (!file_exists($craftFacade)) {
    $craftFacade = __DIR__ . '/../vendor/craftcms/yii2-adapter/legacy/Craft.php';
}
require $craftFacade;

// On Craft 6, craft\base\Field and friends only resolve to the real
// CraftCms\Cms\... classes once craftcms/yii2-adapter's class_alias() calls
// have run — normal app boot (Laravel's service-provider discovery) does
// this automatically, a bare require of the autoloader does not. No-op on
// Craft 5, where the adapter isn't installed at all.
if (class_exists(\CraftCms\Yii2Adapter\ClassAliases::class)) {
    (new \CraftCms\Yii2Adapter\ClassAliases())->register();
}

// Craft 6's Craft::t() routes through a real Laravel Facade (I18N) instead
// of the old no-app-tolerant fallback — with no facade root set, it
// hard-throws ("A facade root has not been set") instead of returning the
// source string. Stand in a container binding a translate() that does the
// same placeholder interpolation Craft 5's fallback did. No-op on Craft 5,
// where Illuminate\Container\Container isn't installed — craftcms/cms 5.x's
// own dependencies can pull in illuminate/support (for Facade itself)
// without illuminate/container, so this checks Container specifically, not
// just Facade.
if (class_exists(\Illuminate\Container\Container::class) && class_exists(\Illuminate\Support\Facades\Facade::class)) {
    $container = new \Illuminate\Container\Container();
    $container->instance(\CraftCms\Cms\Translation\I18N::class, new class() {
        public function translate(\Stringable|string|null $message, array $parameters = [], ?string $category = null, ?string $locale = null): string
        {
            $message = (string) $message;
            foreach ($parameters as $key => $value) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }

            return $message;
        }
    });
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
}
