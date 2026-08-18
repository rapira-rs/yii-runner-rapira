<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature;

use Exception;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeClass;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Throwable;
use Yiisoft\Config\Config;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Config\ConfigPaths;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\Di\BuildingException;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Factory\ThrowableResponseFactory;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;
use Yiisoft\Middleware\Dispatcher\Event\AfterMiddleware;
use Yiisoft\Middleware\Dispatcher\Event\BeforeMiddleware;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\PsrEmitter\FakeEmitter;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Yii\Event\InvalidEventConfigurationFormatException;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Event\AfterEmit;
use Yiisoft\Yii\Http\Event\AfterRequest;
use Yiisoft\Yii\Http\Event\ApplicationShutdown;
use Yiisoft\Yii\Http\Event\ApplicationStartup;
use Yiisoft\Yii\Http\Event\BeforeRequest;
use Yiisoft\Yii\Http\Handler\NotFoundHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;
use Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\CurrentUser;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\CurrentUserMiddleware;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\RapiraWorker;

use function array_key_exists;
use function dirname;
use function set_error_handler;
use function trigger_error;
use function count;

use const E_USER_WARNING;

final class RapiraApplicationRunnerTest
{
    public static bool $bootstrapExecuted = false;
    public static bool $cycleDestroyed = false;

    private RapiraWorker $worker;
    private RapiraApplicationRunner $runner;

    #[BeforeTest]
    public function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::$bootstrapExecuted = false;
        self::$cycleDestroyed = false;

        $this->worker = new RapiraWorker();
        $this->worker->activate();

        $this->runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: true,
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->worker->cleanup();
    }

    #[BeforeClass]
    public static function registerRapiraStub(): void
    {
        RapiraWorker::register();
    }

    #[Test]
    public function testRun(): void
    {
        ob_start();
        $this->runner->run();
        $output = ob_get_clean();

        Assert::same($output, 'OK');
    }

    #[Test]
    public function testRunWithoutBootstrapAndCheckEvents(): void
    {
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: true,
            checkEvents: false,
        );

        ob_start();
        $runner->run();
        $output = ob_get_clean();

        Assert::same($output, 'OK');
    }

    #[Test]
    public function testConstructorDefaultsAreConfiguredAsExpected(): void
    {
        $runner = new RapiraApplicationRunner($this->supportPath());

        Assert::false($this->getPropertyValue($runner, 'debug', ApplicationRunner::class));
        Assert::false($this->getPropertyValue($runner, 'checkEvents', ApplicationRunner::class));
        Assert::same($this->getPropertyValue($runner, 'nestedParamsGroups', ApplicationRunner::class), ['params']);
        Assert::same($this->getPropertyValue($runner, 'nestedEventsGroups', ApplicationRunner::class), ['events']);
    }

    #[Test]
    public function testConstructorKeepsProvidedTemporaryErrorHandler(): void
    {
        $temporaryErrorHandler = new ErrorHandler(new SimpleLogger(), new PlainTextRenderer());

        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            temporaryErrorHandler: $temporaryErrorHandler,
        );

        Assert::same($this->getPropertyValue($runner, 'temporaryErrorHandler'), $temporaryErrorHandler);
    }

    #[Test]
    public function testRunRegistersTemporaryErrorHandlerBeforeContainerIsBuilt(): void
    {
        // The `ErrorHandler` service itself is built while resolving the real error handler
        // from the container, so a warning raised there is only converted into an exception
        // if the temporary error handler has already been registered by `runInternal()`.
        $containerConfig = ContainerConfig::create()->withDefinitions([
            ...$this->createDefinitions(false, false),
            ErrorHandler::class => static function (): ErrorHandler {
                trigger_error('Warning while building the error handler.', E_USER_WARNING);
                return new ErrorHandler(new SimpleLogger(), new PlainTextRenderer());
            },
        ]);

        $runner = $this->runner->withContainer(new Container($containerConfig));

        // Reset the active PHP error handler to a neutral one that swallows warnings instead
        // of throwing, so the assertion below reflects only what `runInternal()` registers.
        set_error_handler(static fn(): bool => true);

        Expect::exception(BuildingException::class)->withMessageContaining('Warning while building the error handler.');

        $runner->run();
    }

    #[Test]
    public function testRunUnregistersTemporaryErrorHandlerAfterContainerIsBuilt(): void
    {
        ob_start();
        $this->runner->run();
        ob_get_clean();

        // Once the actual, container-configured error handler takes over, the temporary one used
        // while building the container must be unregistered, i.e. no longer enabled.
        $temporaryErrorHandler = $this->getPropertyValue($this->runner, 'temporaryErrorHandler');
        $enabled = new ReflectionProperty(ErrorHandler::class, 'enabled');

        Assert::false($enabled->getValue($temporaryErrorHandler));
    }

    #[Test]
    public function testRunWithCustomizedConfiguration(): void
    {
        $container = $this->createContainer();

        $runner = $this->runner
            ->withContainer($container)
            ->withConfig($this->createConfig());

        ob_start();
        $runner->run();
        ob_get_clean();

        /** @var SimpleEventDispatcher $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        Assert::same($dispatcher->getEventClasses(), [
            ApplicationStartup::class,
            BeforeRequest::class,
            BeforeMiddleware::class,
            AfterMiddleware::class,
            AfterRequest::class,
            AfterEmit::class,
            ApplicationShutdown::class,
        ]);
    }

    #[Test]
    public function testRunWithFailureDuringProcess(): void
    {
        $runner = $this->runner->withContainer($this->createContainer(true));

        ob_start();
        $runner->run();
        $output = ob_get_clean();

        Assert::same(preg_match('/^Exception with message "Failure"/', $output), 1);
    }

    #[Test]
    public function testRunReusesErrorCatcherAcrossWorkerRequests(): void
    {
        $this->worker->keepRunningUntil = 2;

        $container = $this->createContainerWithTrackedErrorCatcher();
        $runner = $this->runner->withContainer($container);

        ob_start();
        $runner->run();
        ob_get_clean();

        Assert::same($this->worker->handleRequestCalls, 2);
        // The `ErrorCatcher` must be fetched from the container only once and reused for every
        // subsequent throwable handled within the same worker run, no matter how many requests fail.
        Assert::same(count($container->errorCatcherInstances), 1);
    }

    #[Test]
    public function testRunExecutesBootstrapCallbacks(): void
    {
        $runner = (new RapiraApplicationRunner($this->supportPath(), false))
            ->withContainer($this->createContainer())
            ->withConfig($this->createStubConfig([
                'bootstrap-web' => [
                    static function (ContainerInterface $container): void {
                        self::$bootstrapExecuted = $container instanceof ContainerInterface;
                    },
                ],
            ]));

        ob_start();
        $runner->run();
        ob_get_clean();

        Assert::true(self::$bootstrapExecuted);
    }

    #[Test]
    public function testRunChecksEventsConfigurationWhenEnabled(): void
    {
        $runner = (new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: false,
            checkEvents: true,
        ))
            ->withContainer($this->createContainer())
            ->withConfig($this->createStubConfig([
                'events-web' => ['not-an-event-class' => [static fn() => null]],
            ]));

        Expect::exception(InvalidEventConfigurationFormatException::class);

        $runner->run();
    }

    #[Test]
    public function testRunRethrowsWhenErrorResponseCreationFails(): void
    {
        $runner = $this->runner->withContainer($this->createContainer(true, true));

        Expect::exception(Exception::class)->withMessage('Failure while creating error response');

        $runner->run();
    }

    #[Test]
    public function testRunRethrowsWhenEmitterFails(): void
    {
        $this->worker->keepRunningUntil = 2;

        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            environment: 'view-response-with-error',
            debug: true,
        );

        ob_start();
        $runner->run();
        $output = ob_get_clean();

        Assert::same($this->worker->handleRequestCalls, 2);
        Assert::same(preg_match('/^Exception with message "Failure while creating response stream"/', $output), 1);
    }

    #[Test]
    public function testConfigMergePlanFile(): void
    {
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            configMergePlanFile: 'test-merge-plan.php',
        );

        $params = $runner->getConfig()->get('params-web');

        Assert::same($params, ['a' => 42,]);
    }

    #[Test]
    public function testConfigDirectory(): void
    {
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            configDirectory: 'custom-config',
        );

        $params = $runner->getConfig()->get('params-web');

        Assert::same($params, ['age' => 22]);
    }

    #[Test]
    public function testImmutability(): void
    {
        Assert::notSame($this->runner->withConfig($this->createConfig()), $this->runner);
        Assert::notSame($this->runner->withContainer($this->createContainer()), $this->runner);
    }

    #[Test]
    public function testDoNotModifyExistsContentLength(): void
    {
        $emitter = new FakeEmitter();
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            environment: 'do-not-modify-exists-content-length',
            emitter: $emitter,
        );

        $runner->run();

        $response = $emitter->getLastResponse();
        Assert::instanceOf($response, ResponseInterface::class);
        Assert::same($response->getHeaders(), ['Content-Length' => ['100']]);
    }

    #[Test]
    public function testDoNotAddContentMiddlewareWithContinueStatus(): void
    {
        $emitter = new FakeEmitter();
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            environment: 'do-not-add-content-middleware-with-continue-status',
            emitter: $emitter,
        );

        $runner->run();

        $response = $emitter->getLastResponse();
        Assert::instanceOf($response, ResponseInterface::class);
        Assert::same($response->getHeaders(), []);
    }

    #[Test]
    public function testRunAndGetResponse(): void
    {
        $runner = new RapiraApplicationRunner($this->supportPath(), false);

        ob_start();
        $response = $runner->runAndGetResponse();
        $output = ob_get_clean();

        Assert::same($response->getStatusCode(), 200);
        Assert::same($output, '');
    }

    #[Test]
    public function testRunAndGetResponseWithRequest(): void
    {
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            environment: 'run-without-emit-with-request',
        );

        $request = (new ServerRequest(headers: ['X-CONTENT' => ['Test content']]));

        ob_start();
        $response = $runner->runAndGetResponse($request);
        $output = ob_get_clean();

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getBody()->getContents(), 'Test content');
        Assert::same($output, '');
    }

    #[Test]
    public function testRunAndGetResponseReusesFakeEmitter(): void
    {
        $runner = new RapiraApplicationRunner($this->supportPath(), false);

        $runner->runAndGetResponse();
        $firstEmitter = $this->getPropertyValue($runner, 'fakeEmitter');

        $runner->runAndGetResponse();
        $secondEmitter = $this->getPropertyValue($runner, 'fakeEmitter');

        Assert::same($secondEmitter, $firstEmitter);
    }

    #[Test]
    public function testRunAndGetResponseThrowsWhenNothingWasEmitted(): void
    {
        $this->worker->keepRunningUntil = 0;

        $runner = new RapiraApplicationRunner($this->supportPath(), false);

        Expect::exception(LogicException::class)->withMessage('No response was emitted.');

        $runner->runAndGetResponse();
    }

    #[Test]
    public function testWorkerModeResetsStateBetweenRequests(): void
    {
        $this->worker->keepRunningUntil = 2;

        $emitter = new FakeEmitter();
        $runner = new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: false,
            emitter: $emitter,
        );
        $runner = $runner->withContainer($this->createWorkerModeContainer());

        $runner->run();

        $response = $emitter->getLastResponse();
        Assert::instanceOf($response, ResponseInterface::class);
        Assert::same((string) $response->getBody(), '1');
        Assert::same($this->worker->handleRequestCalls, 2);
    }

    #[Test]
    public function testWorkerModeContinuesUntilHandlerStops(): void
    {
        $this->worker->keepRunningUntil = 2;

        $runner = (new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: false,
        ))->withContainer($this->createWorkerModeContainer());

        ob_start();
        $runner->run();
        ob_get_clean();

        Assert::same($this->worker->handleRequestCalls, 2);
    }

    #[Test]
    public function testWorkerModeDoesNotLeakAuthenticatedUserToNextRequest(): void
    {
        $this->worker->keepRunningUntil = 2;
        $this->worker->requestServerParameters = [
            ['HTTP_X_USER_ID' => 'alice'],
            [],
        ];

        $emitter = new FakeEmitter();
        $runner = (new RapiraApplicationRunner(
            rootPath: $this->supportPath(),
            debug: false,
            emitter: $emitter,
        ))->withContainer($this->createAuthenticatedUserWorkerModeContainer());

        $runner->run();

        $response = $emitter->getLastResponse();
        Assert::instanceOf($response, ResponseInterface::class);
        Assert::same((string) $response->getBody(), 'guest');
        Assert::same($this->worker->handleRequestCalls, 2);
    }

    #[Test]
    public function testRunCollectsCyclicGarbageAfterRequest(): void
    {
        $runner = $this->runner->withContainer($this->createGcCycleContainer());

        ob_start();
        $runner->run();
        ob_get_clean();

        // A reference cycle can never be freed by refcounting alone once both locals holding
        // it go out of scope; only an explicit `gc_collect_cycles()` call reclaims it.
        Assert::true(self::$cycleDestroyed);
    }

    /**
     * A container whose only middleware creates two objects that reference each other and then
     * drops the local variables holding them, forming an unreachable reference cycle. Such a
     * cycle is never released by PHP's refcounting alone; it takes an explicit
     * `gc_collect_cycles()` call to destroy it, which lets the test observe whether that call
     * actually happened.
     */
    private function createGcCycleContainer(): ContainerInterface
    {
        $containerConfig = ContainerConfig::create()->withDefinitions([
            ...$this->createDefinitions(false, false),
            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static function (ContainerInterface $container) {
                            return $container
                                ->get(MiddlewareDispatcher::class)
                                ->withMiddlewares([
                                    static fn() => new class implements MiddlewareInterface {
                                        public function process(
                                            ServerRequestInterface $request,
                                            RequestHandlerInterface $handler,
                                        ): ResponseInterface {
                                            $a = new class {
                                                public mixed $ref = null;

                                                public function __destruct()
                                                {
                                                    RapiraApplicationRunnerTest::$cycleDestroyed = true;
                                                }
                                            };
                                            $b = new class {
                                                public mixed $ref = null;
                                            };
                                            $a->ref = $b;
                                            $b->ref = $a;

                                            return (new ResponseFactory())->createResponse();
                                        }
                                    },
                                ]);
                        },
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ]);

        return new Container($containerConfig);
    }

    private function supportPath(): string
    {
        return dirname(__DIR__) . '/Support';
    }

    private function createContainer(
        bool $throwException = false,
        bool $throwOnErrorResponseCreation = false,
    ): ContainerInterface {
        $containerConfig = ContainerConfig::create()
            ->withDefinitions($this->createDefinitions($throwException, $throwOnErrorResponseCreation));
        return new Container($containerConfig);
    }

    /**
     * A container that always throws on every request (like {@see createContainer()} with
     * `$throwException = true`), but resolves `ErrorCatcher::class` through a factory that builds
     * a fresh instance on every call instead of caching it like {@see Container} does. This lets a
     * test observe how many times `ErrorCatcher::class` is actually fetched from the container,
     * regardless of the real container's own singleton behavior.
     */
    private function createContainerWithTrackedErrorCatcher(): ContainerInterface
    {
        $inner = $this->createContainer(true);

        return new class ($inner) implements ContainerInterface {
            /** @var list<ErrorCatcher> */
            public array $errorCatcherInstances = [];

            public function __construct(private readonly ContainerInterface $inner) {}

            public function get(string $id): mixed
            {
                if ($id === ErrorCatcher::class) {
                    $errorCatcher = new ErrorCatcher(
                        $this->inner->get(ThrowableResponseFactoryInterface::class),
                        $this->inner->get(EventDispatcherInterface::class),
                    );
                    $this->errorCatcherInstances[] = $errorCatcher;
                    return $errorCatcher;
                }

                return $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                return $id === ErrorCatcher::class || $this->inner->has($id);
            }
        };
    }

    private function createConfig(): Config
    {
        return new Config(new ConfigPaths($this->supportPath(), 'config'), paramsGroup: 'params-web');
    }

    private function createStubConfig(array $configurations): ConfigInterface
    {
        return new class ($configurations) implements ConfigInterface {
            public function __construct(private readonly array $configurations) {}

            public function has(string $group): bool
            {
                return array_key_exists($group, $this->configurations);
            }

            public function get(string $group): array
            {
                return $this->configurations[$group];
            }
        };
    }

    private function createDefinitions(bool $throwException, bool $throwOnErrorResponseCreation): array
    {
        return [
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,

            ThrowableResponseFactoryInterface::class => $throwOnErrorResponseCreation
                ? static fn() => new class implements ThrowableResponseFactoryInterface {
                    public function create(
                        Throwable $throwable,
                        ServerRequestInterface $request,
                    ): ResponseInterface {
                        throw new Exception('Failure while creating error response', previous: $throwable);
                    }
                }
                : [
                    'class' => ThrowableResponseFactory::class,
                    'forceContentType()' => ['text/plain'],
                ],

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static function (ContainerInterface $container) use ($throwException) {
                            return $container
                                ->get(MiddlewareDispatcher::class)
                                ->withMiddlewares([
                                    static fn() => new class ($throwException) implements MiddlewareInterface {
                                        public function __construct(private bool $throwException) {}

                                        public function process(
                                            ServerRequestInterface $request,
                                            RequestHandlerInterface $handler,
                                        ): ResponseInterface {
                                            if ($this->throwException) {
                                                throw new Exception('Failure');
                                            }

                                            return (new ResponseFactory())->createResponse();
                                        }
                                    },
                                ]);
                        },
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ];
    }

    private function createWorkerModeContainer(): ContainerInterface
    {
        $containerConfig = ContainerConfig::create()->withDefinitions([
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,
            'requestCounter' => static fn() => new class {
                public int $value = 0;
            },

            ThrowableResponseFactoryInterface::class => [
                'class' => ThrowableResponseFactory::class,
                'forceContentType()' => ['text/plain'],
            ],

            StateResetter::class => static function (ContainerInterface $container): StateResetter {
                $resetter = new StateResetter($container);
                $resetter->setResetters([
                    'requestCounter' => function (): void {
                        $this->value = 0;
                    },
                ]);

                return $resetter;
            },

            'applicationMiddleware' => static fn(ContainerInterface $container) => new class (
                $container->get('requestCounter'),
            ) implements MiddlewareInterface {
                public function __construct(private readonly object $counter) {}

                public function process(
                    ServerRequestInterface $request,
                    RequestHandlerInterface $handler,
                ): ResponseInterface {
                    $this->counter->value++;

                    return (new Response())->withBody(
                        (new StreamFactory())->createStream((string) $this->counter->value),
                    );
                }
            },

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static fn(ContainerInterface $container) => $container
                            ->get(MiddlewareDispatcher::class)
                            ->withMiddlewares([
                                static fn(ContainerInterface $container) => $container->get('applicationMiddleware'),
                            ]),
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ]);

        return new Container($containerConfig);
    }

    private function createAuthenticatedUserWorkerModeContainer(): ContainerInterface
    {
        $containerConfig = ContainerConfig::create()->withDefinitions([
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,

            ThrowableResponseFactoryInterface::class => [
                'class' => ThrowableResponseFactory::class,
                'forceContentType()' => ['text/plain'],
            ],

            CurrentUser::class => [
                'class' => CurrentUser::class,
                'reset' => function (): void {
                    $this->logout();
                },
            ],

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static fn(ContainerInterface $container) => $container
                            ->get(MiddlewareDispatcher::class)
                            ->withMiddlewares([
                                CurrentUserMiddleware::class,
                            ]),
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ]);

        return new Container($containerConfig);
    }

    private function getPropertyValue(
        object $object,
        string $property,
        string $class = RapiraApplicationRunner::class,
    ): mixed {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }
}
