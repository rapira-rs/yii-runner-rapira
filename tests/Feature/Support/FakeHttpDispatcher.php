<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use LogicException;
use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Http\HttpDispatcherInfo;

use function array_shift;

/**
 * In-memory {@see HttpDispatcher}: hands out the queued exchanges in order, then reports itself
 * drained with {@see ClosedException}, the way the host does at shutdown.
 */
final class FakeHttpDispatcher implements HttpDispatcher
{
    /** @var list<Exchange> */
    private array $queue;

    public function __construct(Exchange ...$exchanges)
    {
        $this->queue = $exchanges;
    }

    public function name(): string
    {
        return 'http';
    }

    public function tryReceive(): ?Exchange
    {
        return array_shift($this->queue);
    }

    public function receive(int $timeout = -1): Exchange
    {
        return array_shift($this->queue) ?? throw new ClosedException('Drained.');
    }

    public function getInfo(): HttpDispatcherInfo
    {
        throw new LogicException('Not supported by the fake.');
    }
}
