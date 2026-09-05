<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Test;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\Support\ServerRequests;

/**
 * Real HTTP requests against `tests/Acceptance/App` served by the `rapira` binary in
 * {@see Mode::Worker}.
 */
#[Test]
#[RunRapira(mode: Mode::Worker, address: self::ADDRESS)]
final class WorkerModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8082';

    protected function mode(): Mode
    {
        return Mode::Worker;
    }
}
