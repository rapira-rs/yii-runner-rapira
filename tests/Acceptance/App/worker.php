<?php

declare(strict_types=1);

use Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner;

require_once \dirname(__DIR__, 3) . '/vendor/autoload.php';

(new RapiraApplicationRunner(
    rootPath: __DIR__,
    debug: true,
))->run();
