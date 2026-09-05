<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use LogicException;
use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\HeadAlreadyWrittenError;
use Rapira\Http\Exception\HeadNotWrittenError;
use Rapira\Http\Exchange;
use Rapira\Http\Request;
use Rapira\InetAddress;
use ValueError;

/**
 * In-memory {@see Exchange} that records what a worker writes into it and enforces the same ordering
 * rules the host does: one final head, body only until `$eos`, nothing after finalization.
 *
 * Mark it {@see discard()}ed to simulate a host that closed the exchange before the worker took it, or
 * {@see discardOnWrite()} for one that closed it while the request was being handled.
 */
final class FakeExchange implements Exchange
{
    /** @var int<100, 599>|null */
    public ?int $status = null;

    /** @var array<non-empty-string, list<string>> */
    public array $headers = [];

    /** @var list<string> Body chunks in the order they were written, empty ones included. */
    public array $chunks = [];

    /** @var array<non-empty-string, list<string>>|null */
    public ?array $trailers = null;

    public int $flushes = 0;

    private bool $finalized = false;
    private bool $discarded = false;
    private bool $discardOnWrite = false;

    public function __construct(
        private readonly Request $request = new Request(
            method: 'GET',
            uri: 'http://localhost/',
            target: '/',
            authority: 'localhost',
            protocol: 'HTTP/1.1',
            headers: [],
            body: '',
            remote: new InetAddress('127.0.0.1', 40000),
            server: new InetAddress('127.0.0.1', 8080),
            tls: null,
            receivedAt: 0.0,
        ),
    ) {}

    public function __destruct() {}

    /** The host closed the exchange: every write from now on throws {@see WorkDiscardedException}. */
    public function discard(): void
    {
        $this->discarded = true;
    }

    /** The host closes the exchange while the request is being handled: the first write finds it gone. */
    public function discardOnWrite(): void
    {
        $this->discardOnWrite = true;
    }

    public function getBody(): string
    {
        return implode('', $this->chunks);
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function writeHead(int $status, array $headers = []): void
    {
        $this->assertOpen();
        if ($status < 100 || $status > 599) {
            throw new ValueError("Status $status is outside 100-599.");
        }
        if ($status >= 100 && $status < 200 && $status !== 101) {
            // Interim heads are advisory and repeatable; the fake does not record them.
            return;
        }
        if ($this->status !== null) {
            throw new HeadAlreadyWrittenError();
        }

        $this->status = $status;
        $this->headers = $headers;
    }

    public function writeBody(string $content, bool $eos = true): void
    {
        $this->assertOpen();
        $this->status ??= 200;
        $this->chunks[] = $content;
        if ($eos) {
            $this->finalized = true;
        }
    }

    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        throw new LogicException('Not supported by the fake.');
    }

    public function writeTrailers(array $trailers): void
    {
        $this->assertOpen();
        if ($this->status === null) {
            throw new HeadNotWrittenError();
        }

        $this->trailers = $trailers;
        $this->finalized = true;
    }

    public function flush(): void
    {
        $this->assertOpen();
        $this->status ??= 200;
        $this->flushes++;
    }

    public function isFinalized(): bool
    {
        return $this->finalized || $this->discarded;
    }

    public function isCancelled(): bool
    {
        return $this->discarded;
    }

    private function assertOpen(): void
    {
        $this->discarded = $this->discarded || $this->discardOnWrite;
        if ($this->discarded) {
            throw new WorkDiscardedException();
        }
        if ($this->finalized) {
            throw new AlreadyFinalizedError();
        }
    }
}
