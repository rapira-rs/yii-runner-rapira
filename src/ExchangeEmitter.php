<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Rapira\Http\Exchange;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\PsrEmitter\SapiEmitter;

use function strtolower;

/**
 * Emits a PSR-7 response into a Rapira {@see Exchange} in dispatcher mode.
 *
 * The counterpart of {@see SapiEmitter}: instead of PHP's `header()` and `echo` it writes the response
 * through the exchange the host handed the worker. One emitter serves one exchange, and one response
 * finalizes it.
 *
 * The body is streamed, not buffered: a body whose size fits the buffer goes out in one write together
 * with the head, anything larger or of unknown size goes out chunk by chunk.
 */
final class ExchangeEmitter implements EmitterInterface
{
    private const DEFAULT_BUFFER_SIZE = 8_388_608; // 8MB

    /**
     * @param Exchange $exchange The exchange to write the response into.
     * @param int $bufferSize Bytes per body write. A body no longer than this is sent in a single write.
     */
    public function __construct(
        private readonly Exchange $exchange,
        private readonly int $bufferSize = self::DEFAULT_BUFFER_SIZE,
    ) {
        if ($bufferSize < 1) {
            throw new InvalidArgumentException('Buffer size must be greater than zero.');
        }
    }

    public function emit(ResponseInterface $response): void
    {
        /** @var int<100, 599> $status */
        $status = $response->getStatusCode();
        $headers = $this->collectHeaders($response);
        $body = $response->getBody();

        if (!$body->isReadable()) {
            $this->exchange->writeHead($status, $headers);
            $this->exchange->writeBody('');
            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $size = $body->getSize();
        if ($size !== null && $size <= $this->bufferSize) {
            // Read before the head goes out, so a body that fails to render leaves the exchange untouched
            // and an error response can still be written into it.
            $contents = $body->getContents();
            $this->exchange->writeHead($status, $headers);
            $this->exchange->writeBody($contents);
            return;
        }

        // Streaming: hand the host the length when the stream knows it, so an HTTP/1.1 response keeps
        // `content-length` instead of falling back to chunked encoding.
        if ($size !== null) {
            $headers['content-length'] = [(string) $size];
        }

        $this->streamBody($status, $headers, $body);
    }

    /**
     * @param int<100, 599> $status
     * @param array<non-empty-string, list<string>> $headers
     */
    private function streamBody(int $status, array $headers, StreamInterface $body): void
    {
        // The first chunk is read before the head is committed, for the same reason the buffered path
        // reads the whole body first: a failure at the start can still be answered.
        $chunk = $body->eof() ? '' : $body->read($this->bufferSize);
        $this->exchange->writeHead($status, $headers);

        while (!$body->eof()) {
            // An empty chunk without `$eos` is a no-op on the exchange; skip the call.
            if ($chunk !== '') {
                $this->exchange->writeBody($chunk, eos: false);
            }
            $chunk = $body->read($this->bufferSize);
        }

        // The last chunk carries the end of the stream, so a body that ends on a full read costs no extra write.
        $this->exchange->writeBody($chunk);
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private function collectHeaders(ResponseInterface $response): array
    {
        $headers = [];
        /**
         * @var non-empty-string $name
         * @var list<string> $values
         */
        foreach ($response->getHeaders() as $name => $values) {
            // The host enforces a `content-length` it is given against the bytes actually written, so a
            // stale one carried by the response would reject the whole response. The emitter supplies its
            // own when the body length is known and streamed.
            if (strtolower($name) === 'content-length') {
                continue;
            }
            $headers[$name] = $values;
        }

        return $headers;
    }
}
