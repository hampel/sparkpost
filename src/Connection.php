<?php

declare(strict_types=1);

namespace Hampel\SparkPost;

use Hampel\SparkPost\Exception\ApiException;
use Hampel\SparkPost\Exception\InvalidArgumentException;
use Hampel\SparkPost\Exception\RequestException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one, which is
 * the whole point of the package: a host application with its own HTTP stack - a proxy-aware,
 * SSRF-guarded client that all outbound requests are required to go through - implements
 * sendRequest() over it and shares this code, instead of writing a second API client because
 * ours hardcoded the wrong library.
 *
 * A PSR-18 client does not throw on an HTTP status, only on a transport failure, so the
 * two failure modes stay cleanly separated here.
 */
final class Connection
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send($this->request('GET', $path, $query));
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function post(string $path, array $payload): array
    {
        $request = $this->request('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(self::encode($payload)));

        return $this->send($request);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function request(string $method, string $path, array $query = []): RequestInterface
    {
        return $this->requestFactory
            ->createRequest($method, $this->config->resolve($path, $query))
            // SparkPost wants the raw key. No Bearer prefix - it 401s with one.
            ->withHeader('Authorization', $this->config->apiKey)
            ->withHeader('Accept', 'application/json');
    }

    /**
     * @return array<mixed>
     */
    private function send(RequestInterface $request): array
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->logger->debug('SparkPost request', ['method' => $method, 'uri' => $uri]);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('SparkPost request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            throw RequestException::for($method, $uri, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = self::decode($body);

        if ($status >= 200 && $status < 300) {
            return $decoded ?? [];
        }

        // Be defensive about the body: SparkPost is not the only thing that can answer on
        // this URL, and a proxy or gateway in front of it will return HTML.
        $this->logger->error('SparkPost error response', [
            'method' => $method,
            'uri' => $uri,
            'status' => $status,
            'body' => $decoded ?? $body,
        ]);

        throw ApiException::fromResponse($method, $uri, $response, $decoded, $body);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(
                'Could not encode the request payload as JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @return array<mixed>|null  null when the body was not JSON, or was JSON but not an object
     */
    private static function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
