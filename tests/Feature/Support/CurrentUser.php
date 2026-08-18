<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

/**
 * Minimal stateful "current user" service used to prove the runner resets container state between
 * worker requests (see the authenticated-user leak test).
 */
final class CurrentUser
{
    private ?string $id = null;

    public function authenticate(string $id): void
    {
        $this->id = $id;
    }

    public function logout(): void
    {
        $this->id = null;
    }

    public function name(): string
    {
        return $this->id ?? 'guest';
    }
}
