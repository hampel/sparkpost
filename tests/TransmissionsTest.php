<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ClientException;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\Exception\InvalidArgumentException;
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
    /**
     * The log line that used to carry the payload. These assertions are the fix: a
     * transmission is made of content that must never be logged at any level, so the line
     * describes the send instead of reproducing it.
     */
    public function test_no_part_of_the_message_reaches_the_log(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();

        $this->sparkpost(null, $logger)->transmissions()->send([
            'content' => [
                'subject' => 'Reset your password',
                'html' => '<p>Click https://forum.example.com/reset?token=abc123</p>',
                'text' => 'Click https://forum.example.com/reset?token=abc123',
                'headers' => ['X-Whatever' => 'something'],
                'attachments' => [['name' => 'invoice.pdf', 'type' => 'application/pdf', 'data' => str_repeat('A', 500)]],
                'inline_images' => [['name' => '0', 'type' => 'image/png', 'data' => str_repeat('B', 500)]],
            ],
            'substitution_data' => ['reset_token' => 'abc123'],
        ]);

        $everything = json_encode($logger->records, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Reset your password', $everything);
        $this->assertStringNotContainsString('abc123', $everything);
        $this->assertStringNotContainsString('X-Whatever', $everything);
        $this->assertStringNotContainsString(str_repeat('A', 100), $everything);
        $this->assertStringNotContainsString(str_repeat('B', 100), $everything);
    }

    public function test_the_log_describes_the_transmission_it_will_not_quote(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();

        $this->sparkpost(null, $logger)->transmissions()->send([
            'campaign_id' => 'invoices',
            'options' => ['transactional' => true],
            'recipients' => [['address' => ['email' => 'alice@example.com', 'header_to' => 'alice@example.com']]],
            'content' => [
                'subject' => 'Your invoice',
                'attachments' => [['name' => 'invoice.pdf', 'data' => 'AAAA']],
            ],
            'substitution_data' => ['first_name' => 'Alice'],
        ]);

        $this->assertSame([
            'campaign_id' => 'invoices',
            'template_id' => null,
            'recipient_count' => 1,
            'recipients' => ['alice@example.com'],
            'transactional' => true,
            'sandbox' => null,
            'attachment_count' => 1,
            'inline_image_count' => 0,
            'has_substitution_data' => true,
            'return_path' => null,
        ], $logger->contextFor('SparkPost transmission'));
    }

    /**
     * Addresses are the one piece of personal data the line keeps, because tracing a
     * delivery complaint is what it is for. The cap is what stops a bulk send putting
     * thousands of them on one line, and the count stays truthful.
     */
    public function test_recipient_addresses_are_kept_but_capped(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();
        $recipients = [];

        for ($i = 0; $i < 25; $i++) {
            $recipients[] = ['address' => ['email' => "user{$i}@example.com"]];
        }

        $this->sparkpost(null, $logger)->transmissions()->send(['recipients' => $recipients]);

        $logged = $logger->contextFor('SparkPost transmission');

        $this->assertSame(25, self::path($logged, 'recipient_count'));
        $this->assertCount(10, self::arrayAt($logged, 'recipients'));
        $this->assertSame('user0@example.com', self::path($logged, 'recipients.0'));
    }

    public function test_describing_for_the_log_does_not_change_what_is_sent(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $payload = str_repeat('A', 500);

        $this->sparkpost()->transmissions()->send([
            'content' => ['subject' => 'Hi', 'attachments' => [['name' => 'invoice.pdf', 'data' => $payload]]],
        ]);

        $this->assertSame($payload, self::path($this->sentBody(), 'content.attachments.0.data'));
        $this->assertSame('Hi', self::path($this->sentBody(), 'content.subject'));
    }

    /**
     * The counts an operator actually wants, and nothing in them is personal or unbounded.
     */
    public function test_the_result_is_logged(): void
    {
        $this->client->pushJson(200, self::ACCEPTED);

        $logger = new RecordingLogger();

        $this->sparkpost(null, $logger)->transmissions()->send([]);

        $this->assertSame([
            'transmission_id' => '11668787484950529',
            'total_accepted_recipients' => 2,
            'total_rejected_recipients' => 0,
        ], $logger->contextFor('SparkPost transmission sent'));
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

    /**
     * This record is written in production - error is always above the threshold, and the
     * handler that emails them is floored there too - so what it carries matters more than
     * anything at debug. The reason comes from the parsed errors[]; the body is described
     * rather than quoted, because whatever answered decided its contents.
     */
    public function test_an_error_response_is_logged_without_its_body(): void
    {
        $body = '<html><body>Bad Gateway: upstream said ' . str_repeat('x', 400) . '</body></html>';

        $this->client->pushRaw(502, $body);

        $logger = new RecordingLogger();

        try {
            $this->sparkpost(null, $logger)->transmissions()->send([]);
            $this->fail('Expected a ServerException.');
        } catch (ServerException) {
            // expected
        }

        $logged = $logger->contextFor('SparkPost error response');

        $this->assertSame(502, self::path($logged, 'status'));
        $this->assertSame([], self::arrayAt($logged, 'errors'));
        $this->assertSame(strlen($body), self::path($logged, 'body_length'));
        $this->assertArrayNotHasKey('body', (array) $logged);
        $this->assertStringNotContainsString(str_repeat('x', 100), json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_the_parsed_errors_are_what_the_log_carries(): void
    {
        $this->client->pushJson(422, ['errors' => [['message' => 'nope', 'code' => '1902']]]);

        $logger = new RecordingLogger();

        try {
            $this->sparkpost(null, $logger)->transmissions()->send([]);
        } catch (ClientException) {
            // expected
        }

        $logged = $logger->contextFor('SparkPost error response');

        $this->assertSame('nope', self::path($logged, 'errors.0.message'));
        $this->assertSame('1902', self::path($logged, 'errors.0.code'));
    }

    /**
     * The message is what a caller writing ['exception' => $e] puts in the log, so the
     * unparseable body it quotes is capped. The whole body stays on $body.
     */
    public function test_an_unparseable_body_is_capped_in_the_message_and_whole_on_the_exception(): void
    {
        $body = '<html><body>Bad Gateway ' . str_repeat('x', 400) . '</body></html>';

        $this->client->pushRaw(502, $body);

        try {
            $this->sparkpost()->transmissions()->send([]);
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertStringContainsString('Bad Gateway', $e->getMessage());
            $this->assertStringEndsWith('...', $e->getMessage());
            $this->assertLessThan(300, strlen($e->getMessage()));
            $this->assertSame($body, $e->body);
        }
    }
    /**
     * The other side of the PSR-18 seam: this one is caught before the network, so it is
     * an InvalidArgumentException rather than anything that implies SparkPost was asked.
     * Malformed UTF-8 is the realistic way in - a subject or a name copied out of a
     * database in the wrong encoding.
     */
    public function test_an_unencodable_payload_is_rejected_before_the_request(): void
    {
        $sparkpost = $this->sparkpost();

        try {
            $sparkpost->transmissions()->send([
                'recipients' => [['address' => ['email' => 'alice@example.com']]],
                'content' => ['subject' => "\xB1\x31", 'text' => 'Body.'],
            ]);

            $this->fail('An unencodable payload should not have been sent.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Could not encode the request payload', $e->getMessage());
        }

        // and nothing was handed to the client
        $this->assertSame([], $this->client->requests);
    }
}
