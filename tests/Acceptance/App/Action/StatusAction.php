<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

use function getmypid;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class StatusAction implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $body = json_encode(['status' => 'ok', 'pid' => getmypid()], JSON_THROW_ON_ERROR);

        return (new Response(Status::OK, ['Content-Type' => 'application/json']))->withBody(
            (new StreamFactory())->createStream($body),
        );
    }
}
