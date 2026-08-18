<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use HttpSoft\Message\Response;
use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates the {@see CurrentUser} from the `X-User-Id` header and echoes the resolved name,
 * so a test can observe whether user state leaks from one worker request into the next.
 */
final class CurrentUserMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $userId = $request->getHeaderLine('X-User-Id');
        if ($userId !== '') {
            $this->currentUser->authenticate($userId);
        }

        return (new Response())->withBody(
            (new StreamFactory())->createStream($this->currentUser->name()),
        );
    }
}
