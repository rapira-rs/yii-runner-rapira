<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Test;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\Support\ServerRequests;

/**
 * Real HTTP requests against `tests/Acceptance/App` served by the `rapira` binary in
 * {@see Mode::Dispatcher}.
 */
#[Test]
#[RunRapira(mode: Mode::Dispatcher)]
final class DispatcherModeTest
{
    use ServerRequests;

    protected function mode(): Mode
    {
        return Mode::Dispatcher;
    }
}
