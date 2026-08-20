<?php

declare(strict_types=1);

namespace Hampel\SparkPost;

use Hampel\SparkPost\Resource\Transmissions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point. Hand it a key and any PSR-18 client:
 *
 *     $guzzle  = new GuzzleHttp\Client();
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *
 *     $sparkpost = new SparkPost(new Config($key), $guzzle, $factory, $factory);
 *     $result    = $sparkpost->transmissions()->send($transmission);
 */
final class SparkPost
{
    private readonly Connection $connection;

    private ?Transmissions $transmissions = null;

    public function __construct(
        private readonly Config $config,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->connection = new Connection($this->config, $client, $requestFactory, $streamFactory, $this->logger);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function transmissions(): Transmissions
    {
        return $this->transmissions ??= new Transmissions($this->connection, $this->logger);
    }

    /**
     * For endpoints this package has not wrapped yet - call them directly rather than
     * waiting for a release.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
