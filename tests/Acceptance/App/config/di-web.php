<?php

declare(strict_types=1);

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Factory\ThrowableResponseFactory;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;
use Yiisoft\Injector\Injector;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\UrlMatcherInterface;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\NotFoundHandler;

return [
    EventDispatcherInterface::class => SimpleEventDispatcher::class,
    LoggerInterface::class => SimpleLogger::class,
    ResponseFactoryInterface::class => ResponseFactory::class,
    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
    StreamFactoryInterface::class => StreamFactory::class,
    ThrowableRendererInterface::class => PlainTextRenderer::class,
    UriFactoryInterface::class => UriFactory::class,
    UploadedFileFactoryInterface::class => UploadedFileFactory::class,

    ThrowableResponseFactoryInterface::class => [
        'class' => ThrowableResponseFactory::class,
        'forceContentType()' => ['text/plain'],
    ],

    RouteCollectionInterface::class => [
        'class' => RouteCollection::class,
        '__construct()' => [
            'collector' => DynamicReference::to(
                static fn() => (new RouteCollector())->addRoute(...require __DIR__ . '/routes.php'),
            ),
        ],
    ],

    UrlMatcherInterface::class => static fn(Injector $injector) => $injector->make(UrlMatcher::class, [
        // Route caching is irrelevant for a short-lived test worker and would otherwise require
        // a bound Psr\SimpleCache\CacheInterface.
        'cache' => null,
    ]),

    CurrentRoute::class => [
        'reset' => function (): void {
            $this->route = null;
            $this->uri = null;
            $this->arguments = [];
        },
    ],

    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to(
                static fn(ContainerInterface $container) => $container
                    ->get(MiddlewareDispatcher::class)
                    ->withMiddlewares([
                        Router::class,
                    ]),
            ),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],
];
