<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomeAction implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return (new Response())->withBody((new StreamFactory())->createStream('OK'));
    }
}
