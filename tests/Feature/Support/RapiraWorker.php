<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support;

use Rapira\Dispatcher;
use Rapira\Exception\NoDispatcherError;
use Rapira\Mode;

use function function_exists;

/**
 * Test double for the Rapira runtime.
 *
 * Registers the global `Rapira\*` functions the runner relies on and lets a test script the process:
 * which {@see Mode} it reports, which {@see Dispatcher} it hands out, how many worker iterations to
 * run and which per-request `$_SERVER` parameters each iteration exposes.
 */
final class RapiraWorker
{
    /** The mode `Rapira\get_mode()` reports. */
    public Mode $mode = Mode::Worker;

    /** The dispatcher `Rapira\get_dispatcher()` returns; null makes it refuse as outside {@see Mode::Dispatcher}. */
    public ?Dispatcher $dispatcher = null;

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
     * Register the global `Rapira\*` functions once per process. They delegate to the worker marked
     * active via {@see activate()}.
     */
    public static function register(): void
    {
        if (!function_exists('Rapira\handle_request')) {
            eval(<<<'PHP_WRAP'
            namespace Rapira {
                function get_mode(): Mode
                {
                    return \Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\RapiraWorker::mode();
                }

                function get_dispatcher(): Dispatcher
                {
                    return \Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\RapiraWorker::dispatcher();
                }

                function handle_request(callable $handler): bool
                {
                    return \Yiisoft\Yii\Runner\Rapira\Tests\Feature\Support\RapiraWorker::dispatch($handler);
                }
            }
            PHP_WRAP);
        }
    }

    /** Make this worker the target of the global `Rapira\*` functions. */
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

    public static function mode(): Mode
    {
        return self::$active?->mode ?? Mode::Worker;
    }

    public static function dispatcher(): Dispatcher
    {
        return self::$active?->dispatcher ?? throw new NoDispatcherError('No dispatcher outside dispatcher mode.');
    }

    public static function dispatch(callable $handler): bool
    {
        return self::$active?->handle($handler) ?? $handler();
    }

    private function handle(callable $handler): bool
    {
        // No pending requests at all: mirrors the real worker returning false without ever
        // invoking the handler, e.g. when the process is asked to shut down immediately.
        if ($this->handleRequestCalls >= $this->keepRunningUntil) {
            return false;
        }

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
