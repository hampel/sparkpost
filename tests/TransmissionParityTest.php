<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Transmission\Attachment;
use Hampel\SparkPost\Transmission\Transmission;
use PHPUnit\Framework\TestCase;

/**
 * Parity with the WordPress plugin.
 *
 * The fixtures in tests/fixtures/wordpress-plugin are the real output of that plugin's
 * build_transmission(), captured by running its code - see the README beside them. Each
 * test here builds the same message through this package and asserts the payload matches.
 *
 * assertSame rather than assertEquals is deliberate: it compares key order too, so the
 * payloads are identical rather than merely equivalent. That is stricter than SparkPost
 * requires, and it is what makes a drift show up as a failure instead of a shrug.
 */
final class TransmissionParityTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function fixture(string $name): array
    {
        $path = __DIR__ . '/fixtures/wordpress-plugin/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            self::fail(sprintf('Fixture "%s" could not be read.', $name));
        }

        return $decoded;
    }

    public function test_plain_text(): void
    {
        $transmission = Transmission::make()
            ->from('webmaster@example.com', 'Webmaster')
            ->subject('Hello')
            ->text('Plain body.')
            ->to('alice@example.com', 'Alice')
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional();

        $this->assertSame(self::fixture('plain-text'), $transmission->toArray());
    }

    public function test_html_with_a_plain_text_alternative(): void
    {
        $transmission = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->html('<p>HTML body.</p>')
            ->text('Plain alternative.')
            ->to('alice@example.com', 'Alice')
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional();

        $this->assertSame(self::fixture('html-with-alt'), $transmission->toArray());
    }

    /**
     * The one that carries the most knowledge: how To, Cc and Bcc are each addressed.
     */
    public function test_to_cc_and_bcc(): void
    {
        $transmission = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->text('Body.')
            ->to('alice@example.com', 'Alice')
            ->to('amy@example.com')
            ->cc('bob@example.com', 'Bob')
            ->bcc('carol@example.com')
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional();

        $this->assertSame(self::fixture('to-cc-bcc'), $transmission->toArray());
    }

    public function test_reply_to_and_custom_headers(): void
    {
        $transmission = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->text('Body.')
            ->to('alice@example.com', 'Alice')
            ->replyTo('reply@example.com', 'Reply Desk')
            ->replyTo('second@example.com')
            ->header('X-Campaign', 'spring')
            ->header('Subject', 'should be dropped')
            ->header('Message-ID', 'should be dropped')
            ->header('X-Empty', '')
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional();

        $this->assertSame(self::fixture('headers'), $transmission->toArray());
    }

    public function test_attachments_and_inline_images(): void
    {
        $transmission = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->html('<p>See <img src="cid:0"></p>')
            ->to('alice@example.com', 'Alice')
            ->attach(Attachment::fromData('invoice.pdf', 'application/pdf', 'INVOICE-BYTES'))
            ->attach(Attachment::inline('0', 'image/png', 'LOGO-BYTES'))
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional();

        $this->assertSame(self::fixture('attachments'), $transmission->toArray());
    }

    public function test_the_sandbox_domain_and_a_return_path(): void
    {
        $transmission = Transmission::make()
            ->from('test@SparkPostBox.com')
            ->subject('Hello')
            ->text('Body.')
            ->to('alice@example.com', 'Alice')
            ->returnPath('bounces@example.com')
            ->openTracking(true)
            ->clickTracking(true)
            ->transactional(false);

        $this->assertSame(self::fixture('sandbox-and-return-path'), $transmission->toArray());
    }

    /**
     * Every fixture is exercised by a test above. This fails if one is added and forgotten.
     */
    public function test_every_fixture_is_covered(): void
    {
        $fixtures = glob(__DIR__ . '/fixtures/wordpress-plugin/*.json');

        $this->assertNotFalse($fixtures);
        $this->assertCount(6, $fixtures);
    }
}
