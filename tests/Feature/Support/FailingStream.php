<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function array_shift;
use function implode;

use const SEEK_SET;

/**
 * A read-only, unseekable stream of unknown size that yields the given chunks and then fails on the
 * next read, the way a lazily rendered body fails halfway through.
 */
final class FailingStream implements StreamInterface
{
    public const string MESSAGE = 'Failure while reading the response body';

    /** @var list<string> */
    private array $chunks;

    public function __construct(string ...$chunks)
    {
        $this->chunks = $chunks;
    }

    public function __toString(): string
    {
        return implode('', $this->chunks);
    }

    public function read(int $length): string
    {
        return array_shift($this->chunks) ?? throw new RuntimeException(self::MESSAGE);
    }

    public function getContents(): string
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function eof(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
