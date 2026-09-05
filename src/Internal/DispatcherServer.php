<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Internal;

use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Throwable;
use Yiisoft\Yii\Runner\Rapira\ExchangeEmitter;

/**
 * Serves {@see Mode::Dispatcher}: takes {@see Exchange} units from the HTTP dispatcher and writes each
 * response back through its exchange.
 *
 * One unit at a time, on a blocking `receive()`. The application's services live for the whole process
 * and are reset between requests, so two requests in flight at once would share them.
 *
 * @internal
 */
final readonly class DispatcherServer
{
    public function __construct(
        private RequestCycle $cycle,
        private DispatcherRequestFactory $requestFactory,
        private HttpDispatcher $dispatcher,
    ) {}

    /**
     * Keeps receiving exchanges until the dispatcher is drained.
     *
     * Handling and emitting are separate steps: a handler failure is answered while the exchange is
     * still untouched. Emitting can fail too, and until the head is committed an error response can
     * still take the place of the one that failed; after that the attempt throws, the unit is left
     * unfinalized and the host fails it. The teardown runs whatever happened to the response.
     */
    public function run(): void
    {
        try {
            while (true) {
                $exchange = $this->dispatcher->receive();

                // The host closed the unit while it waited in the queue: the client left or the deadline
                // passed. Nothing the application produces for it would be accepted.
                if ($exchange->isCancelled()) {
                    continue;
                }

                $request = $this->cycle->begin($this->requestFactory->create($exchange));
                $response = $this->cycle->handle($request);
                $emitter = new ExchangeEmitter($exchange);

                try {
                    $emitter->emit($response);
                } catch (WorkDiscardedException) {
                    // The host closed the exchange meanwhile and has already failed it. Nobody to answer.
                } catch (Throwable $throwable) {
                    // A body that failed to render, a header the wire rejects, or a fault in the emitter
                    // itself. The error catcher logs it either way; the answer is best effort.
                    $response = $this->cycle->recover($request, $throwable);
                    try {
                        $emitter->emit($response);
                    } catch (Throwable) {
                    }
                } finally {
                    $this->cycle->finish($response);
                }
            }
        } catch (ClosedException) {
            // Drained: no more work will ever arrive.
        }
    }
}
