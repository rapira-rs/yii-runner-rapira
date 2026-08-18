<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Testo;

use Internal\Container\Container;
use Override;
use RuntimeException;
use Testo\Application\Config\Plugin\SuitePlugins;
use Testo\Common\EventListenerCollector;
use Testo\Common\PluginConfigurator;
use Testo\Event\TestSuite\TestSuiteFinished;
use Testo\Event\TestSuite\TestSuiteStarting;

use function escapeshellarg;
use function exec;
use function explode;
use function fclose;
use function file_exists;
use function fsockopen;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function usleep;

use const DIRECTORY_SEPARATOR;

/**
 * Testo plugin that manages the `rapira` server lifecycle.
 *
 * Starts the `rapira serve` process (using the `rapira.toml` found in {@see $workingDirectory}) when the
 * test suite begins and stops it when the suite finishes. The plugin should be attached to a specific
 * suite via {@see SuitePlugins::with()}.
 *
 * @see https://rapira.rs/
 */
final class RapiraBinPlugin implements PluginConfigurator
{
    /** @var resource|null rapira process handle */
    private $process = null;

    /**
     * @param non-empty-string $binary Absolute path to the rapira executable.
     * @param non-empty-string $workingDirectory Absolute path to the application directory containing
     * `worker.php` and `rapira.toml`. The process is started with this directory as its working
     * directory, so `rapira` picks up the `rapira.toml` located there.
     * @param non-empty-string $address Host and port the application listens on (e.g. "127.0.0.1:8080"),
     * as configured in `rapira.toml`. Used only to detect readiness.
     */
    public function __construct(
        private readonly string $binary,
        private readonly string $workingDirectory,
        private readonly string $address = '127.0.0.1:8080',
    ) {}

    #[Override]
    public function configure(Container $container): void
    {
        $listeners = $container->get(EventListenerCollector::class);

        $listeners->addListener(TestSuiteStarting::class, $this->start(...));
        $listeners->addListener(TestSuiteFinished::class, $this->stop(...));
    }

    /**
     * Start the rapira server as a background process.
     */
    private function start(TestSuiteStarting $event): void
    {
        if ($this->process !== null) {
            return;
        }

        if (!file_exists($this->binary)) {
            throw new RuntimeException("rapira binary not found at: {$this->binary}");
        }

        $command = sprintf('%s serve', escapeshellarg($this->binary));

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $this->process = proc_open($command, $descriptors, $pipes, $this->workingDirectory);

        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start rapira process');
        }

        $this->waitForReady();
    }

    /**
     * Stop the rapira server when the suite finishes.
     */
    private function stop(TestSuiteFinished $event): void
    {
        $this->killProcess();
    }

    /**
     * Terminate the rapira process.
     *
     * On Windows, uses `taskkill` to kill the process tree.
     * On Unix, sends SIGTERM.
     */
    private function killProcess(): void
    {
        if ($this->process === null) {
            return;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            if (DIRECTORY_SEPARATOR === '\\') {
                exec(sprintf('taskkill /F /T /PID %d 2>NUL', $status['pid']));
            } else {
                proc_terminate($this->process, 15);
            }
        }

        proc_close($this->process);
        $this->process = null;
    }

    /**
     * Poll the server address until it accepts TCP connections (up to 5 seconds).
     */
    private function waitForReady(): void
    {
        [$host, $port] = explode(':', $this->address);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000); // 50ms between attempts
        }

        $this->killProcess();
        throw new RuntimeException("rapira did not start within 5 seconds on {$this->address}");
    }
}
