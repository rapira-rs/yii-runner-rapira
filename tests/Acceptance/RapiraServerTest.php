<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\Rapira\Tests\Acceptance;

use JsonException;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Yiisoft\Yii\Runner\Rapira\Tests\Testo\RapiraBinPlugin;

use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function json_decode;
use function sprintf;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const JSON_THROW_ON_ERROR;

/**
 * Runs real HTTP requests against a Yii3 application served by the `rapira` binary
 * (see {@see RapiraBinPlugin} and `tests/Acceptance/App`).
 */
#[Test]
final class RapiraServerTest
{
    private const BASE_URL = 'http://127.0.0.1:8080';

    public function homeReturnsOk(): void
    {
        [$status, $body] = $this->request('/');

        Assert::same($status, 200);
        Assert::same($body, 'OK');
    }

    public function helloUsesRouteArgument(): void
    {
        [$status, $body] = $this->request('/hello/Rapira');

        Assert::same($status, 200);
        Assert::same($body, 'Hello, Rapira!');
    }

    public function statusReturnsJson(): void
    {
        [$status, $body] = $this->request('/status');

        Assert::same($status, 200);

        /** @var array{status: string} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        Assert::same($data['status'], 'ok');
    }

    public function unknownRouteReturns404(): void
    {
        [$status] = $this->request('/unknown-route');

        Assert::same($status, 404);
    }

    /**
     * @return array{0: int, 1: string} The response status code and body.
     *
     * @throws JsonException
     */
    private function request(string $path): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => self::BASE_URL . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException(sprintf('Request to %s failed: %s', $path, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [$status, $body];
    }
}
