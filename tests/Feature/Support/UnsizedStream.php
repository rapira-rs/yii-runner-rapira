<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use Psr\Http\Message\StreamInterface;

use const SEEK_SET;

/**
 * Decorates a stream so that it reports no size, the way a generator-backed or network stream does.
 */
final class UnsizedStream implements StreamInterface
{
    public function __construct(
        private readonly StreamInterface $inner,
    ) {}

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
