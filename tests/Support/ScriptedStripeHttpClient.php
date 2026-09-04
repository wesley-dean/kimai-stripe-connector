<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;
use Throwable;

final class ScriptedStripeHttpClient implements ClientInterface
{
    /**
     * @var list<array{body: string, status: int, headers: array<string, string>}|Throwable>
     */
    private array $responses;

    /**
     * @var list<array{
     *     method: string,
     *     url: string,
     *     headers: array<int, string>,
     *     params: array<string, mixed>,
     *     has_file: bool,
     *     api_mode: string,
     *     max_network_retries: ?int
     * }>
     */
    public array $requests = [];

    /**
     * @param list<array{body: string, status: int, headers?: array<string, string>}|Throwable> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = array_map(
            static function (array|Throwable $response): array|Throwable {
                if ($response instanceof Throwable) {
                    return $response;
                }

                return [
                    'body' => $response['body'],
                    'status' => $response['status'],
                    'headers' => $response['headers'] ?? [],
                ];
            },
            $responses,
        );
    }

    /**
     * @param string $method
     * @param string $absUrl
     * @param array<int, string> $headers
     * @param array<string, mixed> $params
     * @param bool $hasFile
     * @param string $apiMode
     * @param ?int $maxNetworkRetries
     *
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request(
        $method,
        $absUrl,
        $headers,
        $params,
        $hasFile,
        $apiMode = 'v1',
        $maxNetworkRetries = null,
    ) {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
            'has_file' => $hasFile,
            'api_mode' => $apiMode,
            'max_network_retries' => $maxNetworkRetries,
        ];

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('Scripted Stripe HTTP client has no response configured.');
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        return [$response['body'], $response['status'], $response['headers']];
    }
}
