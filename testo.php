<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Application\Config\SuiteConfig;
use Rapira\Sdk\Testing\Testo\RunRapiraPlugin;

$projectRoot = __DIR__;
$isWindows = \DIRECTORY_SEPARATOR === '\\';
$appDirectory = $projectRoot . '/tests/Acceptance/App';
// The rapira binary (and its bundled `libphp`) is fetched by dload into `runtime/bin`; the plugin
// downloads it on demand when this path is missing.
$rapiraBinary = $projectRoot . '/runtime/bin/rapira' . ($isWindows ? '.exe' : '');

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(name: 'Feature', location: ['tests/Feature']),
        new SuiteConfig(
            name: 'Acceptance',
            location: ['tests/Acceptance'],
            plugins: SuitePlugins::with(
                new RunRapiraPlugin(
                    binary: $rapiraBinary,
                    workingDirectory: $appDirectory,
                ),
            ),
        ),
    ],
);
