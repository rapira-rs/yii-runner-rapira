<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature;

use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rapira\Dispatcher;
use Rapira\DispatcherInfo;
use Rapira\Http\Request;
use Rapira\InetAddress;
use Rapira\Mode;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Rapira\Work;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeClass;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Event\AfterEmit;
use Yiisoft\Yii\Runner\Rapira\Internal\DispatcherServer;
use Yiisoft\Yii\Runner\Rapira\Internal\RequestCycle;
use Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\FakeExchange;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\FakeHttpDispatcher;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\RapiraWorker;

use function dirname;
use function str_contains;

final class DispatcherModeTest
{
    private RapiraWorker $worker;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->worker = new RapiraWorker();
        $this->worker->mode = Mode::Dispatcher;
        $this->worker->activate();
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
    public function servesEveryExchangeUntilDrained(): void
    {
        $first = new FakeExchange($this->request('first'));
        $second = new FakeExchange($this->request('second'));
        $this->worker->dispatcher = new FakeHttpDispatcher($first, $second);

        $this->runner('run-without-emit-with-request')->run();

        Assert::same($first->status, 200);
        Assert::same($first->getBody(), 'first');
        Assert::same($second->getBody(), 'second');
        Assert::true($first->isFinalized());
        Assert::true($second->isFinalized());
    }

    #[Test]
    public function handlerFailureIsAnsweredWithAnErrorResponse(): void
    {
        $exchange = new FakeExchange();
        $this->worker->dispatcher = new FakeHttpDispatcher($exchange);

        $this->runner('throwing-middleware')->run();

        Assert::same($exchange->status, 500);
        Assert::true(str_contains($exchange->getBody(), 'Failure while handling the request'));
        Assert::true($exchange->isFinalized());
    }

    #[Test]
    public function discardedExchangeStillGetsTheTeardown(): void
    {
        $runner = $this->runner();
        $container = $runner->getContainer();
        /** @var Application $application */
        $application = $container->get(Application::class);
        /** @var SimpleEventDispatcher $events */
        $events = $container->get(EventDispatcherInterface::class);
        /** @var DispatcherRequestFactory $requestFactory */
        $requestFactory = $container->get(DispatcherRequestFactory::class);

        $exchange = new FakeExchange();
        $exchange->discard();
        $server = new DispatcherServer(
            new RequestCycle($container, $application),
            $requestFactory,
            new FakeHttpDispatcher($exchange),
        );

        $server->run();

        Assert::same($exchange->status, null);
        Assert::true($events->isClassTriggered(AfterEmit::class, 1));
    }

    #[Test]
    public function refusesADispatcherOfAnotherPlugin(): void
    {
        $this->worker->dispatcher = new class implements Dispatcher {
            public function name(): string
            {
                return 'jobs';
            }

            public function tryReceive(): ?Work
            {
                return null;
            }

            public function receive(int $timeout = -1): Work
            {
                throw new LogicException('Not supported by the fake.');
            }

            public function getInfo(): DispatcherInfo
            {
                throw new LogicException('Not supported by the fake.');
            }
        };

        Expect::exception(LogicException::class)
            ->withMessage('Only the "http" dispatcher is supported, "jobs" was given.');

        $this->runner()->run();
    }

    private function runner(?string $environment = null): RapiraApplicationRunner
    {
        return new RapiraApplicationRunner(
            rootPath: dirname(__DIR__) . '/Support',
            debug: true,
            environment: $environment,
        );
    }

    /**
     * A request the `run-without-emit-with-request` environment echoes back: its middleware answers with
     * the `X-Content` header value as the body.
     */
    private function request(string $content): Request
    {
        return new Request(
            method: 'GET',
            uri: 'http://localhost/',
            target: '/',
            authority: 'localhost',
            protocol: 'HTTP/1.1',
            headers: ['x-content' => [$content]],
            body: '',
            remote: new InetAddress('127.0.0.1', 40000),
            server: new InetAddress('127.0.0.1', 8080),
            tls: null,
            receivedAt: 0.0,
        );
    }
}
