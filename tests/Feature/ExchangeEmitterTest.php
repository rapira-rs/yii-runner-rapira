<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use InvalidArgumentException;
use Rapira\Exception\WorkDiscardedException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Yii\Runner\Rapira\ExchangeEmitter;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\FakeExchange;
use Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\UnsizedStream;

use function fopen;
use function str_repeat;

final class ExchangeEmitterTest
{
    #[Test]
    public function smallBodyGoesOutInOneWrite(): void
    {
        $exchange = new FakeExchange();
        $response = $this->response(201, ['Content-Type' => 'text/plain', 'X-Id' => ['1', '2']], 'hello');

        (new ExchangeEmitter($exchange))->emit($response);

        Assert::same($exchange->status, 201);
        Assert::same($exchange->headers, ['Content-Type' => ['text/plain'], 'X-Id' => ['1', '2']]);
        Assert::same($exchange->chunks, ['hello']);
        Assert::true($exchange->isFinalized());
    }

    #[Test]
    public function contentLengthOfTheResponseIsNotForwarded(): void
    {
        $exchange = new FakeExchange();
        $response = $this->response(200, ['Content-Length' => '999', 'Content-Type' => 'text/plain'], 'hello');

        (new ExchangeEmitter($exchange))->emit($response);

        Assert::same($exchange->headers, ['Content-Type' => ['text/plain']]);
    }

    #[Test]
    public function largeBodyIsStreamedInChunksWithKnownLength(): void
    {
        $exchange = new FakeExchange();
        $response = $this->response(200, ['Content-Length' => '999'], str_repeat('a', 10));

        (new ExchangeEmitter($exchange, bufferSize: 4))->emit($response);

        Assert::same($exchange->status, 200);
        Assert::same($exchange->headers, ['content-length' => ['10']]);
        Assert::same($exchange->chunks, ['aaaa', 'aaaa', 'aa', '']);
        Assert::true($exchange->isFinalized());
    }

    #[Test]
    public function bodyOfUnknownSizeIsStreamedWithoutLength(): void
    {
        $exchange = new FakeExchange();
        $body = new UnsizedStream((new StreamFactory())->createStream('streamed'));
        $response = (new Response(200, ['Content-Type' => 'text/event-stream']))->withBody($body);

        (new ExchangeEmitter($exchange, bufferSize: 5))->emit($response);

        Assert::same($exchange->headers, ['Content-Type' => ['text/event-stream']]);
        Assert::same($exchange->getBody(), 'streamed');
        Assert::same($exchange->chunks, ['strea', 'med', '']);
    }

    #[Test]
    public function bodyIsRewoundBeforeEmitting(): void
    {
        $exchange = new FakeExchange();
        $response = $this->response(200, [], 'hello');
        $response->getBody()->read(3);

        (new ExchangeEmitter($exchange))->emit($response);

        Assert::same($exchange->getBody(), 'hello');
    }

    #[Test]
    public function emptyBodyStillFinalizesTheExchange(): void
    {
        $exchange = new FakeExchange();

        (new ExchangeEmitter($exchange))->emit($this->response(204));

        Assert::same($exchange->status, 204);
        Assert::same($exchange->chunks, ['']);
        Assert::true($exchange->isFinalized());
    }

    #[Test]
    public function unreadableBodyIsSentAsEmpty(): void
    {
        $exchange = new FakeExchange();
        $body = (new StreamFactory())->createStreamFromResource(fopen('php://stdout', 'wb'));
        $response = (new Response(200))->withBody($body);

        (new ExchangeEmitter($exchange))->emit($response);

        Assert::same($exchange->chunks, ['']);
        Assert::true($exchange->isFinalized());
    }

    #[Test]
    public function discardedExchangeSurfacesAsWorkDiscarded(): void
    {
        $exchange = new FakeExchange();
        $exchange->discard();

        Expect::exception(WorkDiscardedException::class);

        (new ExchangeEmitter($exchange))->emit($this->response(200, [], 'hello'));
    }

    #[Test]
    public function bufferSizeMustBePositive(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ExchangeEmitter(new FakeExchange(), bufferSize: 0);
    }

    /**
     * HttpSoft reads a string body as a stream identifier, so the content goes through the stream factory.
     *
     * @param array<string, string|list<string>> $headers
     */
    private function response(int $status, array $headers = [], string $body = ''): Response
    {
        return (new Response($status, $headers))->withBody((new StreamFactory())->createStream($body));
    }
}
