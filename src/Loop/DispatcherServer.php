<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Loop;

use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Yiisoft\Yii\Runner\Rapira\ExchangeEmitter;

/**
 * Serves {@see Mode::Dispatcher}: takes {@see Exchange} units from the HTTP dispatcher one at a time
 * and writes each response back through its exchange.
 */
final class DispatcherServer
{
    public function __construct(
        private readonly RequestCycle $cycle,
        private readonly DispatcherRequestFactory $requestFactory,
        private readonly HttpDispatcher $dispatcher,
    ) {}

    /**
     * Keeps receiving exchanges until the dispatcher is drained.
     */
    public function run(): void
    {
        try {
            while (true) {
                // Blocks the fiber until a unit arrives; other fibers keep running meanwhile.
                $this->serve($this->dispatcher->receive());
            }
        } catch (ClosedException) {
            // No more work will ever arrive.
        }
    }

    /**
     * Handling and emitting are separate steps: a handler failure is answered while the exchange is
     * still untouched, whereas once the head is committed nothing can be answered anymore. A host that
     * closed the exchange meanwhile — client gone, deadline reached, the worker draining — has already
     * failed the unit, so the write is dropped. The teardown runs whatever happened.
     */
    private function serve(Exchange $exchange): void
    {
        $request = $this->cycle->begin($this->requestFactory->create($exchange));
        $response = $this->cycle->handle($request);

        try {
            (new ExchangeEmitter($exchange))->emit($response);
        } catch (WorkDiscardedException) {
            // Nothing more to send.
        } finally {
            $this->cycle->finish($response);
        }
    }
}
