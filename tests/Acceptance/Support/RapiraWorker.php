<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\Support;

use function function_exists;

/**
 * Test double for the Rapira worker runtime.
 *
 * Registers the global `Rapira\handle_request()` the runner relies on and lets a test script the
 * worker loop: how many iterations to keep running and which per-request `$_SERVER` parameters each
 * iteration exposes. This is the Testo replacement for the `eval()`-based stub and the static
 * bookkeeping that used to live inside the test case.
 */
final class RapiraWorker
{
    /** Number of worker iterations executed so far. */
    public int $handleRequestCalls = 0;

    /** The loop stops once {@see $handleRequestCalls} reaches this value. */
    public int $keepRunningUntil = 1;

    /**
     * Per-iteration `$_SERVER` overrides, keyed by the zero-based iteration index.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $requestServerParameters = [];
    private static ?self $active = null;

    /** @var list<string> Keys injected into `$_SERVER` by the current iteration. */
    private array $requestServerParameterKeys = [];

    /**
     * Register the global `Rapira\handle_request()` function once per process. It delegates to the
     * worker marked active via {@see activate()}.
     */
    public static function register(): void
    {
        if (!function_exists('Rapira\handle_request')) {
            eval(<<<'PHP_WRAP'
            namespace Rapira {
                function handle_request(callable $handler): bool
                {
                    return \Yiisoft\Yii\Runner\Rapira\Tests\Acceptance\Support\RapiraWorker::dispatch($handler);
                }
            }
            PHP_WRAP);
        }
    }

    /** Make this worker the target of the global `Rapira\handle_request()`. */
    public function activate(): void
    {
        self::$active = $this;
    }

    /** Remove any `$_SERVER` keys injected by the last iteration and detach from the global stub. */
    public function cleanup(): void
    {
        foreach ($this->requestServerParameterKeys as $key) {
            unset($_SERVER[$key]);
        }
        $this->requestServerParameterKeys = [];
        self::$active = null;
    }

    public static function dispatch(callable $handler): bool
    {
        return self::$active?->handle($handler) ?? $handler();
    }

    private function handle(callable $handler): bool
    {
        foreach ($this->requestServerParameterKeys as $key) {
            unset($_SERVER[$key]);
        }

        $this->requestServerParameterKeys = [];
        foreach ($this->requestServerParameters[$this->handleRequestCalls] ?? [] as $key => $value) {
            $_SERVER[$key] = $value;
            $this->requestServerParameterKeys[] = $key;
        }

        $this->handleRequestCalls++;

        return $handler() && $this->handleRequestCalls < $this->keepRunningUntil;
    }
}
