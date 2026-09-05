<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Loop;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;

use function gc_collect_cycles;
use function microtime;

/**
 * The part of serving a request that does not depend on where it came from or where the response goes:
 * run it through the application, fall back to an error response on failure, and tear the request down
 * afterwards. The per-mode servers own the transport and call these three steps around it.
 */
final class RequestCycle
{
    private ?ErrorCatcher $errorCatcher = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Application $application,
    ) {}

    /**
     * Stamps the request with the moment the application started serving it.
     */
    public function begin(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute('applicationStartTime', microtime(true));
    }

    /**
     * Runs the request through the application. A failure inside the application is answered by
     * {@see recover()}, so this method always yields a response.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->application->handle($request);
        } catch (Throwable $throwable) {
            return $this->recover($request, $throwable);
        }
    }

    /**
     * Builds the error response for a failure, through the configured {@see ErrorCatcher}.
     */
    public function recover(ServerRequestInterface $request, Throwable $throwable): ResponseInterface
    {
        /** @var ErrorCatcher $errorCatcher */
        $errorCatcher = $this->errorCatcher ??= $this->container->get(ErrorCatcher::class);

        return $errorCatcher->process($request, new ThrowableHandler($throwable));
    }

    /**
     * Per-request teardown: fire `afterEmit`, reset stateful services and collect cyclic garbage, so
     * nothing leaks into the next request served by the same long-lived process.
     */
    public function finish(ResponseInterface $response): void
    {
        $this->application->afterEmit($response);

        /** @var StateResetter $stateResetter */
        $stateResetter = $this->container->get(StateResetter::class);
        $stateResetter->reset();
        gc_collect_cycles();
    }
}
