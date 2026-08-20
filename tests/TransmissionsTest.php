<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ClientException;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\Exception\RateLimitException;
use Hampel\SparkPost\Exception\RequestException;
use Hampel\SparkPost\Exception\ServerException;

final class TransmissionsTest extends TestCase
{
    private const ACCEPTED = [
        'results' => ['id' => '11668787484950529', 'total_accepted_recipients' => 2, 'total_rejected_recipients' => 0],
    ];

    public function test_it_posts_to_the_transmissions_endpoint(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $this->sparkpost()->transmissions()->send(['recipients' => []]);

        $request = $this->client->lastRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sparkpost.com/api/v1/transmissions', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function test_it_sends_the_api_key_raw_without_a_bearer_prefix(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $this->sparkpost(new Config('secret-key'))->transmissions()->send([]);

        $this->assertSame('secret-key', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_it_sends_the_transmission_as_the_json_body(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $transmission = ['recipients' => [['address' => ['email' => 'me@example.com']]], 'content' => ['subject' => 'Hi']];

        $this->sparkpost()->transmissions()->send($transmission);

        $this->assertSame($transmission, $this->sentBody());
    }

    public function test_it_reports_what_sparkpost_accepted(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $result = $this->sparkpost()->transmissions()->send([]);

        $this->assertSame('11668787484950529', $result->id);
        $this->assertSame(2, $result->totalAcceptedRecipients);
        $this->assertTrue($result->wasAccepted());
        $this->assertFalse($result->hasRejections());
    }

    /**
     * The trap this package exists to make visible: HTTP 200, nothing sent.
     */
    public function test_a_200_that_accepted_nobody_is_not_a_send(): void
    {
        $this->client->pushJson(200, [
            'results' => ['id' => '123', 'total_accepted_recipients' => 0, 'total_rejected_recipients' => 1],
        ]);

        $result = $this->sparkpost()->transmissions()->send([]);

        $this->assertFalse($result->wasAccepted());
        $this->assertTrue($result->hasRejections());
        $this->assertSame(1, $result->totalRecipients());
    }

    public function test_a_4xx_throws_a_client_exception_carrying_the_api_errors(): void
    {
        $this->client->pushJson(422, [
            'errors' => [['message' => 'required field is missing', 'description' => 'content.subject', 'code' => '1400']],
        ]);

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a ClientException.');
        } catch (ClientException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('required field is missing content.subject', $e->getMessage());
            $this->assertSame('1400', $e->errors[0]['code']);
        }
    }

    public function test_a_429_throws_a_rate_limit_exception_with_retry_after(): void
    {
        $this->client->pushJson(429, ['errors' => [['message' => 'Too many requests']]], ['Retry-After' => '30']);

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->retryAfter);
            // still a 4xx, so a caller that only cares about client errors still catches it
            $this->assertInstanceOf(ClientException::class, $e);
        }
    }

    public function test_a_5xx_throws_a_server_exception(): void
    {
        $this->client->pushJson(503, ['errors' => [['message' => 'Service unavailable']]]);

        $this->expectException(ServerException::class);

        $this->sparkpost()->transmissions()->send([]);
    }

    /**
     * A proxy or gateway in front of the API answers with HTML, not JSON.
     */
    public function test_a_non_json_error_body_is_reported_rather_than_swallowed(): void
    {
        $this->client->pushRaw(502, '<html><body>Bad Gateway</body></html>');

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame([], $e->errors);
            $this->assertStringContainsString('Bad Gateway', $e->getMessage());
            $this->assertStringContainsString('<html>', $e->body);
        }
    }

    public function test_an_empty_error_body_still_names_the_status(): void
    {
        $this->client->pushRaw(401, '');

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a ClientException.');
        } catch (ClientException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }
    }

    public function test_a_transport_failure_is_distinct_from_an_api_error(): void
    {
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $this->client->push(new TransportFailure($factory->createRequest('POST', 'https://api.sparkpost.com')));

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a RequestException.');
        } catch (RequestException $e) {
            $this->assertStringContainsString('Could not reach SparkPost', $e->getMessage());
            $this->assertInstanceOf(ExceptionInterface::class, $e);
        }
    }
    public function test_it_truncates_attachment_payloads_before_they_reach_the_log(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();
        $payload = str_repeat('A', 500);

        $this->sparkpost(null, $logger)->transmissions()->send([
            'content' => [
                'attachments' => [['name' => 'invoice.pdf', 'type' => 'application/pdf', 'data' => $payload]],
                'inline_images' => [['name' => '0', 'type' => 'image/png', 'data' => $payload]],
            ],
        ]);

        $logged = $logger->contextFor('SparkPost transmission');

        $this->assertSame('<<<truncated>>>', self::path($logged, 'content.attachments.0.data'));
        $this->assertSame('<<<truncated>>>', self::path($logged, 'content.inline_images.0.data'));
        // the rest of the entry survives - the log should still show an attachment was there
        $this->assertSame('invoice.pdf', self::path($logged, 'content.attachments.0.name'));
        $this->assertSame('application/pdf', self::path($logged, 'content.attachments.0.type'));
    }

    public function test_truncating_for_the_log_does_not_change_what_is_sent(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $payload = str_repeat('A', 500);

        $this->sparkpost()->transmissions()->send([
            'content' => ['attachments' => [['name' => 'invoice.pdf', 'data' => $payload]]],
        ]);

        $this->assertSame($payload, self::path($this->sentBody(), 'content.attachments.0.data'));
    }

    public function test_a_short_payload_is_left_alone(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();

        $this->sparkpost(null, $logger)->transmissions()->send([
            'content' => ['attachments' => [['name' => 'note.txt', 'data' => 'c2hvcnQ=']]],
        ]);

        $logged = $logger->contextFor('SparkPost transmission');

        $this->assertSame('c2hvcnQ=', self::path($logged, 'content.attachments.0.data'));
    }

    public function test_a_transmission_without_attachments_passes_through_the_log_untouched(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();
        $transmission = ['content' => ['subject' => 'Hi', 'text' => 'Body']];

        $this->sparkpost(null, $logger)->transmissions()->send($transmission);

        $this->assertSame($transmission, $logger->contextFor('SparkPost transmission'));
    }

    public function test_an_api_error_is_logged_without_the_api_key(): void
    {
        $this->client->pushJson(422, ['errors' => [['message' => 'nope']]]);

        $logger = new RecordingLogger();

        try {
            $this->sparkpost(new Config('super-secret-key'), $logger)->transmissions()->send([]);
        } catch (ClientException) {
            // expected
        }

        $this->assertNotNull($logger->contextFor('SparkPost error response'));
        $this->assertStringNotContainsString('super-secret-key', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }
}
