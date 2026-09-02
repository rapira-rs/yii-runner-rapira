<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira;

use ErrorException;
use JsonException;
use LogicException;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Rapira\Exception\ClosedException;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Rapira\Sdk\Http\SapiRequestFactory;
use Throwable;
use Yiisoft\Definitions\Exception\CircularReferenceException;
use Yiisoft\Definitions\Exception\InvalidConfigException;
use Yiisoft\Definitions\Exception\NotInstantiableException;
use Yiisoft\Di\NotFoundException;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\PsrEmitter\FakeEmitter;
use Yiisoft\PsrEmitter\HeadersHaveBeenSentException;
use Yiisoft\PsrEmitter\SapiEmitter;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

use function function_exists;
use function gc_collect_cycles;
use function ignore_user_abort;
use function microtime;
use function Rapira\get_dispatcher;
use function Rapira\get_mode;
use function Rapira\handle_request;

// Prevent worker script termination when a client connection is interrupted.
ignore_user_abort(true);

/**
 * `RapiraApplicationRunner` runs the Yii HTTP application under Rapira.
 *
 * The mode is a property of how the host launched the process, not of the entry script: the same
 * artifact serves a SAPI worker loop and the dispatcher. The runner detects the {@see Mode} at startup
 * and drives the matching loop, so user code never chooses between them.
 */
final class RapiraApplicationRunner extends ApplicationRunner
{
    private readonly ErrorHandler $temporaryErrorHandler;
    private readonly EmitterInterface $emitter;
    private ?FakeEmitter $fakeEmitter = null;

    /**
     * @param string $rootPath The absolute path to the project root.
     * @param bool $debug Whether the debug mode is enabled.
     * @param bool $checkEvents Whether to check events' configuration.
     * @param string|null $environment The environment name.
     * @param string $bootstrapGroup The bootstrap configuration group name.
     * @param string $eventsGroup The events' configuration group name.
     * @param string $diGroup The container definitions' configuration group name.
     * @param string $diProvidersGroup The container providers' configuration group name.
     * @param string $diDelegatesGroup The container delegates' configuration group name.
     * @param string $diTagsGroup The container tags' configuration group name.
     * @param string $paramsGroup The configuration parameters group name.
     * @param array $nestedParamsGroups Configuration group names that are included in a configuration parameters group.
     * This is needed for recursive merging of parameters.
     * @param array $nestedEventsGroups Configuration group names that are included in events' configuration group.
     * This is needed for the reverse and recursive merge of events' configurations.
     * @param object[] $configModifiers Modifiers for {@see Config}.
     * @param string $configDirectory The relative path from {@see $rootPath} to the configuration storage location.
     * @param string $vendorDirectory The relative path from {@see $rootPath} to the vendor directory.
     * @param string $configMergePlanFile The relative path from {@see $configDirectory} to merge plan.
     * @param ErrorHandler|null $temporaryErrorHandler The temporary error handler instance that used to handle
     * the creation of configuration and container instances, then the error handler configured in your application
     * configuration will be used.
     * @param EmitterInterface|null $emitter The emitter instance to send the response with in SAPI and classic
     * modes. By default, it uses {@see SapiEmitter}. Dispatcher mode always writes through the exchange and
     * ignores this emitter.
     *
     * @psalm-param list<string> $nestedParamsGroups
     * @psalm-param list<string> $nestedEventsGroups
     * @psalm-param list<object> $configModifiers
     */
    public function __construct(
        string $rootPath,
        bool $debug = false,
        bool $checkEvents = false,
        ?string $environment = null,
        string $bootstrapGroup = 'bootstrap-web',
        string $eventsGroup = 'events-web',
        string $diGroup = 'di-web',
        string $diProvidersGroup = 'di-providers-web',
        string $diDelegatesGroup = 'di-delegates-web',
        string $diTagsGroup = 'di-tags-web',
        string $paramsGroup = 'params-web',
        array $nestedParamsGroups = ['params'],
        array $nestedEventsGroups = ['events'],
        array $configModifiers = [],
        string $configDirectory = 'config',
        string $vendorDirectory = 'vendor',
        string $configMergePlanFile = '.merge-plan.php',
        ?ErrorHandler $temporaryErrorHandler = null,
        ?EmitterInterface $emitter = null,
    ) {
        $this->temporaryErrorHandler = $temporaryErrorHandler ?? new ErrorHandler(new NullLogger(), new HtmlRenderer());
        $this->emitter = $emitter ?? new SapiEmitter();

        parent::__construct(
            $rootPath,
            $debug,
            $checkEvents,
            $environment,
            $bootstrapGroup,
            $eventsGroup,
            $diGroup,
            $diProvidersGroup,
            $diDelegatesGroup,
            $diTagsGroup,
            $paramsGroup,
            $nestedParamsGroups,
            $nestedEventsGroups,
            $configModifiers,
            $configDirectory,
            $vendorDirectory,
            $configMergePlanFile,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws CircularReferenceException|ErrorException|InvalidConfigException|JsonException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     */
    #[Override]
    public function run(): void
    {
        [$container, $application] = $this->prepareApplication();

        match ($this->detectMode()) {
            Mode::Dispatcher => $this->runDispatcherLoop($container, $application),
            Mode::Worker => $this->runSapiLoop($container, $application, $this->emitter, null),
            Mode::Classic => $this->runOnce($container, $application, $this->emitter, null),
        };

        $application->shutdown();
    }

    /**
     * Runs the application and gets the response instead of emitting it.
     * This method is useful for testing purposes or when you want to handle the response.
     *
     * @param ServerRequestInterface|null $request The server request to handle (optional).
     * @throws CircularReferenceException|ErrorException|HeadersHaveBeenSentException|InvalidConfigException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     * @return ResponseInterface The response generated by the application.
     */
    public function runAndGetResponse(?ServerRequestInterface $request = null): ResponseInterface
    {
        [$container, $application] = $this->prepareApplication();
        $emitter = $this->fakeEmitter ??= new FakeEmitter();

        // The response is captured, never streamed to a client, so it goes through the SAPI-style
        // single-request path rather than the dispatcher loop even when a dispatcher is present.
        if ($this->detectMode() === Mode::Worker) {
            $this->runSapiLoop($container, $application, $emitter, $request);
        } else {
            $this->runOnce($container, $application, $emitter, $request);
        }

        $application->shutdown();

        return $emitter->getLastResponse()
            ?? throw new LogicException('No response was emitted.');
    }

    /**
     * Builds the container, registers the error handlers, runs the bootstrap and starts the application.
     *
     * @throws CircularReferenceException|ErrorException|InvalidConfigException|JsonException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     *
     * @return array{0: ContainerInterface, 1: Application}
     */
    private function prepareApplication(): array
    {
        // Register temporary error handler to catch error while the container is building.
        $this->registerErrorHandler($this->temporaryErrorHandler);

        $container = $this->getContainer();

        // Register error handler with real container-configured dependencies.
        /** @var ErrorHandler $actualErrorHandler */
        $actualErrorHandler = $container->get(ErrorHandler::class);
        $this->registerErrorHandler($actualErrorHandler, $this->temporaryErrorHandler);

        $this->runBootstrap();
        $this->checkEvents();

        /** @var Application $application */
        $application = $container->get(Application::class);
        $application->start();

        return [$container, $application];
    }

    /**
     * The mode the process runs in. Outside a Rapira runtime — the CLI, tests — the function is absent
     * and the process behaves as {@see Mode::Classic}: one request handled once.
     */
    private function detectMode(): Mode
    {
        return function_exists('Rapira\get_mode') ? get_mode() : Mode::Classic;
    }

    /**
     * SAPI worker loop: keep pulling requests from `Rapira\handle_request()` and emit each response.
     */
    private function runSapiLoop(
        ContainerInterface $container,
        Application $application,
        EmitterInterface $emitter,
        ?ServerRequestInterface $request,
    ): void {
        /** @var SapiRequestFactory $requestFactory */
        $requestFactory = $container->get(SapiRequestFactory::class);
        $errorCatcher = null;

        $handler = function () use (
            $container,
            $application,
            $emitter,
            $requestFactory,
            $request,
            &$errorCatcher,
        ): bool {
            $currentRequest = ($request ?? $requestFactory->create())
                ->withAttribute('applicationStartTime', microtime(true));

            $this->handleRequest($container, $application, $emitter, $currentRequest, $errorCatcher);

            return true;
        };

        while (handle_request($handler));
    }

    /**
     * Dispatcher loop: take {@see Exchange} units from the HTTP dispatcher and write each response back.
     */
    private function runDispatcherLoop(ContainerInterface $container, Application $application): void
    {
        /** @var HttpDispatcher $dispatcher */
        $dispatcher = get_dispatcher();
        /** @var DispatcherRequestFactory $requestFactory */
        $requestFactory = $container->get(DispatcherRequestFactory::class);
        $errorCatcher = null;

        try {
            while (true) {
                // Blocks the fiber until a unit arrives; other fibers keep running meanwhile.
                $exchange = $dispatcher->receive();
                $this->handleExchange($container, $application, $requestFactory, $exchange, $errorCatcher);
            }
        } catch (ClosedException) {
            // The dispatcher is drained: no more work will ever arrive. Leave the loop.
        }
    }

    /**
     * Single request, handled once and returned. Used in classic mode, on the CLI and in tests.
     */
    private function runOnce(
        ContainerInterface $container,
        Application $application,
        EmitterInterface $emitter,
        ?ServerRequestInterface $request,
    ): void {
        /** @var SapiRequestFactory $requestFactory */
        $requestFactory = $container->get(SapiRequestFactory::class);
        $errorCatcher = null;

        $request = ($request ?? $requestFactory->create())
            ->withAttribute('applicationStartTime', microtime(true));

        $this->handleRequest($container, $application, $emitter, $request, $errorCatcher);
    }

    /**
     * Handles one request through an emitter: process it, emit the response, then run the per-request
     * teardown. On a failure while processing or emitting, an error response is emitted instead.
     *
     * @param ErrorCatcher|null $errorCatcher Fetched from the container once and reused across requests.
     */
    private function handleRequest(
        ContainerInterface $container,
        Application $application,
        EmitterInterface $emitter,
        ServerRequestInterface $request,
        ?ErrorCatcher &$errorCatcher,
    ): void {
        try {
            $response = $application->handle($request);
            $emitter->emit($response);
        } catch (Throwable $throwable) {
            $errorCatcher ??= $container->get(ErrorCatcher::class);
            /** @var ErrorCatcher $errorCatcher */
            $response = $errorCatcher->process($request, new ThrowableHandler($throwable));
            $emitter->emit($response);
        }

        $this->afterRequest($container, $application, $response);
    }

    /**
     * Handles one dispatcher exchange: process it and write the response back through the exchange.
     *
     * Processing and emitting are separate steps. A handler failure is answered with an error response
     * while the exchange is still untouched. Emitting streams the body, so once the head is committed
     * nothing can be answered anymore: a host that closed the exchange meanwhile — client gone, deadline
     * reached, the worker draining — surfaces as {@see WorkDiscardedException}, the unit is already
     * failed on the host side and is dropped here. The per-request teardown runs whatever happened, so
     * a dropped exchange leaks no state into the next one.
     *
     * @param ErrorCatcher|null $errorCatcher Fetched from the container once and reused across exchanges.
     */
    private function handleExchange(
        ContainerInterface $container,
        Application $application,
        DispatcherRequestFactory $requestFactory,
        Exchange $exchange,
        ?ErrorCatcher &$errorCatcher,
    ): void {
        $request = $requestFactory->create($exchange)
            ->withAttribute('applicationStartTime', microtime(true));

        try {
            $response = $application->handle($request);
        } catch (Throwable $throwable) {
            $errorCatcher ??= $container->get(ErrorCatcher::class);
            /** @var ErrorCatcher $errorCatcher */
            $response = $errorCatcher->process($request, new ThrowableHandler($throwable));
        }

        try {
            (new ExchangeEmitter($exchange))->emit($response);
        } catch (WorkDiscardedException) {
            // Nothing more to send: the host has already failed the unit.
        } finally {
            $this->afterRequest($container, $application, $response);
        }
    }

    /**
     * Per-request teardown shared by every mode: fire `afterEmit`, reset stateful services and collect
     * cyclic garbage, so nothing leaks into the next request served by the same long-lived process.
     */
    private function afterRequest(
        ContainerInterface $container,
        Application $application,
        ResponseInterface $response,
    ): void {
        $application->afterEmit($response);

        /** @var StateResetter $stateResetter */
        $stateResetter = $container->get(StateResetter::class);
        $stateResetter->reset();
        gc_collect_cycles();
    }

    /**
     * @throws ErrorException
     */
    private function registerErrorHandler(ErrorHandler $registered, ?ErrorHandler $unregistered = null): void
    {
        $unregistered?->unregister();

        if ($this->debug) {
            $registered->debug();
        }

        $registered->register();
    }
}
