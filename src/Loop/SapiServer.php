<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Loop;

use Psr\Http\Message\ServerRequestInterface;
use Rapira\Mode;
use Rapira\Sdk\Http\SapiRequestFactory;
use Throwable;
use Yiisoft\PsrEmitter\EmitterInterface;

use function Rapira\handle_request;

/**
 * Serves requests that arrive through the SAPI superglobals and answers them through an
 * {@see EmitterInterface}: the {@see Mode::Worker} loop over `Rapira\handle_request()`, and the single
 * pass of {@see Mode::Classic}, the CLI and tests.
 */
final readonly class SapiServer
{
    /**
     * @param ServerRequestInterface|null $request A request to serve instead of the one read from the
     * superglobals. Every iteration serves this same request when set.
     */
    public function __construct(
        private RequestCycle $cycle,
        private SapiRequestFactory $requestFactory,
        private EmitterInterface $emitter,
        private ?ServerRequestInterface $request = null,
    ) {}

    /**
     * Keeps pulling requests from `Rapira\handle_request()` until the host stops handing them out.
     */
    public function run(): void
    {
        $handler = function (): bool {
            $this->serve();

            return true;
        };

        while (handle_request($handler));
    }

    /**
     * Serves exactly one request.
     */
    public function once(): void
    {
        $this->serve();
    }

    private function serve(): void
    {
        $request = $this->cycle->begin($this->request ?? $this->requestFactory->create());
        $response = $this->cycle->handle($request);

        try {
            try {
                $this->emitter->emit($response);
            } catch (Throwable $throwable) {
                // Typically a body that failed to render. The SAPI buffers output, so an error response
                // can still replace what was about to go out.
                $response = $this->cycle->recover($request, $throwable);
                $this->emitter->emit($response);
            }
        } finally {
            $this->cycle->finish($response);
        }
    }
}
