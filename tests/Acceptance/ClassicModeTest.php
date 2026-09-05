<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance;

use Rapira\Sdk\Common\Mode;
use Rapira\Sdk\Testing\Testo\Attribute\RunRapira;
use Testo\Test;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\Support\ServerRequests;

/**
 * Real HTTP requests against `tests/Acceptance/App` served by the `rapira` binary in
 * {@see Mode::Classic}.
 */
#[Test]
#[RunRapira(mode: Mode::Classic, address: self::ADDRESS)]
final class ClassicModeTest
{
    use ServerRequests;

    private const ADDRESS = '127.0.0.1:8081';

    protected function mode(): Mode
    {
        return Mode::Classic;
    }
}
