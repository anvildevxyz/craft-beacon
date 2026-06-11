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
require __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';
