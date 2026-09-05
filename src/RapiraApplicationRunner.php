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
use Rapira\Http\HttpDispatcher;
use Rapira\Mode;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Rapira\Sdk\Http\SapiRequestFactory;
use Yiisoft\Definitions\Exception\CircularReferenceException;
use Yiisoft\Definitions\Exception\InvalidConfigException;
use Yiisoft\Definitions\Exception\NotInstantiableException;
use Yiisoft\Di\NotFoundException;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\PsrEmitter\FakeEmitter;
use Yiisoft\PsrEmitter\HeadersHaveBeenSentException;
use Yiisoft\PsrEmitter\SapiEmitter;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Runner\ApplicationRunner;
use Yiisoft\Yii\Runner\Rapira\Internal\DispatcherServer;
use Yiisoft\Yii\Runner\Rapira\Internal\RequestCycle;
use Yiisoft\Yii\Runner\Rapira\Internal\SapiServer;

use function function_exists;
use function ignore_user_abort;
use function Rapira\get_dispatcher;
use function Rapira\get_mode;
use function sprintf;

// Prevent worker script termination when a client connection is interrupted.
ignore_user_abort(true);

/**
 * `RapiraApplicationRunner` runs the Yii HTTP application under Rapira.
 *
 * The mode is a property of how the host launched the process, not of the entry script: the same
 * artifact serves every {@see Mode}. The runner detects the mode at startup and drives the matching
 * server, so user code never chooses between them. {@see Mode::Classic} and {@see Mode::Worker} share
 * the SAPI transport ({@see SapiServer}); {@see Mode::Dispatcher} answers through exchanges
 * ({@see DispatcherServer}).
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
     * @param EmitterInterface|null $emitter The emitter instance to send the response with in the SAPI
     * modes, {@see Mode::Classic} and {@see Mode::Worker}. By default, it uses {@see SapiEmitter}.
     * {@see Mode::Dispatcher} always writes through the exchange and ignores this emitter.
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
        $cycle = new RequestCycle($container, $application);

        match ($this->detectMode()) {
            Mode::Dispatcher => $this->dispatcherServer($container, $cycle)->run(),
            Mode::Worker => $this->sapiServer($container, $cycle, $this->emitter)->run(),
            Mode::Classic => $this->sapiServer($container, $cycle, $this->emitter)->once(),
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
        $server = $this->sapiServer($container, new RequestCycle($container, $application), $emitter, $request);

        // The response is captured, never streamed to a client, so it goes through the SAPI path even
        // when a dispatcher is present.
        if ($this->detectMode() === Mode::Worker) {
            $server->run();
        } else {
            $server->once();
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

    private function sapiServer(
        ContainerInterface $container,
        RequestCycle $cycle,
        EmitterInterface $emitter,
        ?ServerRequestInterface $request = null,
    ): SapiServer {
        /** @var SapiRequestFactory $requestFactory */
        $requestFactory = $container->get(SapiRequestFactory::class);

        return new SapiServer($cycle, $requestFactory, $emitter, $request);
    }

    private function dispatcherServer(ContainerInterface $container, RequestCycle $cycle): DispatcherServer
    {
        /** @var DispatcherRequestFactory $requestFactory */
        $requestFactory = $container->get(DispatcherRequestFactory::class);
        $dispatcher = get_dispatcher();
        // The pool may serve any plugin; this runner speaks HTTP only.
        $dispatcher instanceof HttpDispatcher or throw new LogicException(
            sprintf('Only the "http" dispatcher is supported, "%s" was given.', $dispatcher->name()),
        );

        return new DispatcherServer($cycle, $requestFactory, $dispatcher);
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
