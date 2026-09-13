<?php

declare(strict_types=1);

namespace Hampel\SparkPost;

use Hampel\SparkPost\Resource\MessageEvents;
use Hampel\SparkPost\Resource\SendingDomains;
use Hampel\SparkPost\Resource\Suppression;
use Hampel\SparkPost\Resource\Transmissions;
use Hampel\SparkPost\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point. Hand it a key and any PSR-18 client:
 *
 *     $sparkpost = SparkPost::withKey($key, new GuzzleHttp\Client());
 *     $result    = $sparkpost->transmissions()->send($transmission);
 *
 * Or in full, to choose the PSR-17 factories or build the Config yourself:
 *
 *     $factory   = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *     $sparkpost = new SparkPost(new Config($key), $guzzle, $factory, $factory);
 *
 * The PSR-18 client is always passed and never discovered: which client makes the request is
 * the one decision a host application may not be allowed to delegate.
 */
final class SparkPost
{
    private readonly Connection $connection;

    private ?Transmissions $transmissions = null;

    private ?MessageEvents $messageEvents = null;

    private ?Suppression $suppression = null;

    private ?SendingDomains $sendingDomains = null;

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros', whichever is installed;
     *         see Psr17Discovery. Pass them to choose, or when none of those is present.
     */
    public function __construct(
        private readonly Config $config,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection($this->config, $client, $requestFactory, $streamFactory, $this->logger);
    }

    /**
     * The short form: an API key, a transport, and optionally a region.
     *
     * Everything the long constructor takes is still available on it; this exists because
     * naming a Config to accept its defaults is ceremony, and ceremony in an example is what
     * gets copied. The region comes before the factories because it is the argument a caller
     * on the EU tenancy cannot do without - `SparkPost::withKey($key, $client, 'eu')`.
     */
    public static function withKey(
        #[\SensitiveParameter] string $key,
        ClientInterface $client,
        ?string $region = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            Config::forRegion($key, $region),
            $client,
            $requestFactory,
            $streamFactory,
            $logger ?? new NullLogger()
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function transmissions(): Transmissions
    {
        return $this->transmissions ??= new Transmissions($this->connection, $this->logger);
    }

    public function messageEvents(): MessageEvents
    {
        return $this->messageEvents ??= new MessageEvents($this->connection, $this->logger);
    }

    public function suppression(): Suppression
    {
        return $this->suppression ??= new Suppression($this->connection, $this->logger);
    }

    public function sendingDomains(): SendingDomains
    {
        return $this->sendingDomains ??= new SendingDomains($this->connection, $this->logger);
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
