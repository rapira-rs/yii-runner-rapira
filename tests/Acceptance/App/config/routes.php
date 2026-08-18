<?php

declare(strict_types=1);

use Yiisoft\Router\Route;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action\HelloAction;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action\HomeAction;
use Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\App\Action\StatusAction;

return [
    Route::get('/')
        ->action(HomeAction::class)
        ->name('home'),
    Route::get('/hello/{name}')
        ->action(HelloAction::class)
        ->name('hello'),
    Route::get('/status')
        ->action(StatusAction::class)
        ->name('status'),
];
