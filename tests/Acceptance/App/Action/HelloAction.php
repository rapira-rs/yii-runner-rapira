<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

final class HelloAction implements MiddlewareInterface
{
    public function __construct(private readonly CurrentRoute $currentRoute) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $name = $this->currentRoute->getArgument('name', 'World');

        return (new Response())->withBody(
            (new StreamFactory())->createStream("Hello, {$name}!"),
        );
    }
}
