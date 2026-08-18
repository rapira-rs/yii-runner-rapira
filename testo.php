<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Application\Config\SuiteConfig;
use Yiisoft\Yii\Runner\Rapira\Tests\Testo\RapiraBinPlugin;

$projectRoot = __DIR__;
$isWindows = \DIRECTORY_SEPARATOR === '\\';
$appDirectory = $projectRoot . '/tests/Acceptance/App';
// The rapira binary is fetched by dload into `runtime/` (see `dload.xml`); the release tarball keeps
// its `bin/rapira` + `lib/rapira/` layout nested, so the executable lives at `runtime/bin/rapira`.
$rapiraBinary = $projectRoot . '/runtime/bin/rapira' . ($isWindows ? '.exe' : '');

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(name: 'Unit', location: ['tests/Unit']),
        new SuiteConfig(name: 'Feature', location: ['tests/Feature']),
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance'],
            plugins: SuitePlugins::with(
                new RapiraBinPlugin(
                    binary: $rapiraBinary,
                    workingDirectory: $appDirectory,
                    address: '127.0.0.1:8080',
                ),
            ),
        ),
    ],
);
