<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii Rapira worker runner</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/yii-runner-rapira/v)](https://packagist.org/packages/yiisoft/yii-runner-rapira)
[![Total Downloads](https://poser.pugx.org/yiisoft/yii-runner-rapira/downloads)](https://packagist.org/packages/yiisoft/yii-runner-rapira)
[![Build status](https://github.com/yiisoft/yii-runner-rapira/actions/workflows/build.yml/badge.svg)](https://github.com/yiisoft/yii-runner-rapira/actions/workflows/build.yml)
[![Code Coverage](https://codecov.io/gh/yiisoft/yii-runner-rapira/branch/master/graph/badge.svg)](https://codecov.io/gh/yiisoft/yii-runner-rapira)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fyii-runner-rapira%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/yii-runner-rapira/master)
[![static analysis](https://github.com/yiisoft/yii-runner-rapira/workflows/static%20analysis/badge.svg)](https://github.com/yiisoft/yii-runner-rapira/actions?query=workflow%3A%22static+analysis%22)
[![type-coverage](https://shepherd.dev/github/yiisoft/yii-runner-rapira/coverage.svg)](https://shepherd.dev/github/yiisoft/yii-runner-rapira)

The package contains a bootstrap for running Yii3 application using [Rapira](https://rapira.rs/) worker mode.

> [!NOTE]
> If you do not want to run Yii3 in worker mode, please use [yiisoft/yii-runner-http](https://github.com/yiisoft/yii-runner-http) which is default for [yiisoft/app](https://github.com/yiisoft/app) and [yiisoft/app-api](https://github.com/yiisoft/app-api).

## Requirements

- PHP 8.1 - 8.5.

## Installation

The package could be installed with [Composer](https://getcomposer.org):

```shell
composer require yiisoft/yii-runner-rapira
```

## General usage

In your application root create `worker.php`:

```php
<?php

declare(strict_types=1);

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner;

$root = __DIR__;

require_once $root . '/src/bootstrap.php';

$runner = new RapiraApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    temporaryErrorHandler: new ErrorHandler(
        new Logger(
            [
                (new StreamTarget())->setLevels([
                    LogLevel::EMERGENCY,
                    LogLevel::ERROR,
                    LogLevel::WARNING,
                ]),
            ],
        ),
        new PlainTextRenderer(),
    ),
);
$runner->run();
```

### Running

Create a `rapira.toml` next to your `worker.php`:

```toml
[http]
listen = "127.0.0.1:8000"

[pool]
entrypoint = "worker.php"
mode = "worker"
```

Then start the server:

```shell
rapira serve
```

See the [Rapira documentation](https://rapira.rs/) for the full list of configuration options.

### Worker mode notes

To recycle a worker after a number of handled requests, set `max_requests` in the `[pool]` section of your
`rapira.toml`.

When using worker mode, make sure stateful services are reset after each request. For resetters configuration, see
[Yii DI `StateResetter` documentation](https://github.com/yiisoft/di#resetting-services-state).

### Additional configuration

By default, the `RapiraApplicationRunner` is configured to work with Yii application templates and follows the
[config groups convention](https://github.com/yiisoft/docs/blob/master/022-config-groups.md).

You can override the default configuration using constructor parameters and immutable setters.

#### Constructor parameters

`$rootPath` — the absolute path to the project root.

`$debug` — whether the debug mode is enabled.

`$checkEvents` — whether check events' configuration.

`$environment` — the environment name.

`$bootstrapGroup` — the bootstrap configuration group name.

`$eventsGroup` — the events' configuration group name.

`$diGroup` — the container definitions' configuration group name.

`$diProvidersGroup` — the container providers' configuration group name.

`$diDelegatesGroup` — the container delegates' configuration group name.

`$diTagsGroup` — the container tags' configuration group name.

`$paramsGroup` — the config parameters group name.

`$nestedParamsGroups` — configuration group names that are included in a config parameters group. This is needed for
recursive merge parameters.

`$nestedEventsGroups` — configuration group names that are included in events' configuration group. This is needed for
reverse and recursive merge events' configurations.

`$configModifiers` — [configuration modifiers](https://github.com/yiisoft/config#configuration-modifiers).

`$configDirectory` — the relative path from `$rootPath` to the configuration storage location.

`$vendorDirectory` — the relative path from `$rootPath` to the vendor directory.

`$configMergePlanFile` — the relative path from `$configDirectory` to merge plan.

`$temporaryErrorHandler` — A temporary error handler is needed to handle the creation of configuration and
container instances, then the error handler configured in your application configuration will be used.

`$emitter` — an emitter to send the response.

#### Immutable setters

If the configuration instance settings differ from the default, you can specify a customized configuration instance:

```php
/**
 * @var Yiisoft\Config\ConfigInterface $config
 * @var Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner $runner
 */

$runner = $runner->withConfig($config);
```

The default container is `Yiisoft\Di\Container`. But you can specify any implementation
of the `Psr\Container\ContainerInterface`:

```php
/**
 * @var Psr\Container\ContainerInterface $container
 * @var Yiisoft\Yii\Runner\Rapira\RapiraApplicationRunner $runner
 */

$runner = $runner->withContainer($container);
```

## Documentation

- [Internals](docs/internals.md)

If you need help or have a question, the [Yii Forum](https://forum.yiiframework.com/c/yii-3-0/63) is a good place for
that. You may also check out other [Yii Community Resources](https://www.yiiframework.com/community).

## License

The Yii Rapira worker Runner is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)

## Follow updates

[![Official website](https://img.shields.io/badge/Powered_by-Yii_Framework-green.svg?style=flat)](https://www.yiiframework.com/)
[![Twitter](https://img.shields.io/badge/twitter-follow-1DA1F2?logo=twitter&logoColor=1DA1F2&labelColor=555555?style=flat)](https://twitter.com/yiiframework)
[![Telegram](https://img.shields.io/badge/telegram-join-1DA1F2?style=flat&logo=telegram)](https://t.me/yii3en)
[![Facebook](https://img.shields.io/badge/facebook-join-1DA1F2?style=flat&logo=facebook&logoColor=ffffff)](https://www.facebook.com/groups/yiitalk)
[![Slack](https://img.shields.io/badge/slack-join-1DA1F2?style=flat&logo=slack)](https://yiiframework.com/go/slack)
