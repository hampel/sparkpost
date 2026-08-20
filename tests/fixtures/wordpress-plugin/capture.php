<?php

/**
 * Recapture the fixtures beside this file from the WordPress plugin.
 *
 *     php tests/fixtures/wordpress-plugin/capture.php
 *
 * The plugin file is loaded verbatim - nothing is retyped - so the fixtures are what the
 * plugin actually does rather than what we believe it does. Only WordPress itself is
 * stubbed, and build_transmission() is reached by reflection because it is private.
 *
 * Set SPARKPOST_WP_PLUGIN to the plugin directory if it is not at the default path. This
 * file is dev-only and never ships: tests/ is export-ignored.
 *
 * Nothing here is loaded by the test suite. TransmissionParityTest reads the JSON.
 */

namespace {

    define('ABSPATH', __DIR__);

    // --- WordPress globals the mailer reaches for -------------------------------------

    function apply_filters($hook, $value, ...$args)
    {
        return $value;
    }
    function do_action($hook, ...$args)
    {
    }
    function is_email($email)
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    class WP_Error
    {
    }

}

namespace PHPMailer\PHPMailer {
    class Exception extends \Exception
    {
    }
}

namespace {

    /**
     * Stands in for PHPMailer, exposing only what build_transmission() reads.
     */
    class WP_PHPMailer
    {
        public const CONTENT_TYPE_PLAINTEXT = 'text/plain';

        public static $validator;

        public $From = '';
        public $FromName = '';
        public $Subject = '';
        public $ContentType = 'text/html';
        public $Body = '';
        public $AltBody = '';
        public $to = [];
        public $ReplyTo = [];

        private $cc = [];
        private $bcc = [];
        private $headers = [];
        private $attachments = [];

        public function __construct($exceptions = true)
        {
        }

        public function getCcAddresses()
        {
            return $this->cc;
        }
        public function getBccAddresses()
        {
            return $this->bcc;
        }
        public function getCustomHeaders()
        {
            return $this->headers;
        }
        public function getAttachments()
        {
            return $this->attachments;
        }

        public function setCc(array $cc)
        {
            $this->cc = $cc;
        }
        public function setBcc(array $bcc)
        {
            $this->bcc = $bcc;
        }
        public function setHeaders(array $h)
        {
            $this->headers = $h;
        }
        public function setAttachments(array $a)
        {
            $this->attachments = $a;
        }
    }

}

namespace SparkPostMailer {

    const VERSION = '1.0.0';

    /** Stands in for the plugin's own Settings, which reads WordPress options. */
    class Settings
    {
        public static array $values = [
            'enabled' => true,
            'api_key' => 'test-key',
            'from_email' => '',
            'from_name' => '',
            'return_path' => '',
            'region' => 'us',
            'transactional' => true,
            'open_tracking' => false,
            'click_tracking' => false,
        ];

        public static function all(): array
        {
            return self::$values;
        }
        public static function get(string $key)
        {
            return self::$values[$key] ?? null;
        }
        public static function api_base(): string
        {
            return 'https://api.sparkpost.com';
        }
    }

}

namespace {

    $plugin = getenv('SPARKPOST_WP_PLUGIN')
        ?: '/srv/www/wordpress.local/wp-content/plugins/sparkpost-mailer';

    if (!is_file($plugin . '/includes/class-mailer.php')) {
        fwrite(STDERR, "Could not find the plugin at {$plugin}.\n");
        fwrite(STDERR, "Set SPARKPOST_WP_PLUGIN to its directory.\n");

        exit(1);
    }

    require $plugin . '/includes/class-mailer.php';

    function build(callable $configure, array $settings = []): array
    {
        SparkPostMailer\Settings::$values = array_merge([
            'enabled' => true, 'api_key' => 'test-key', 'from_email' => '', 'from_name' => '',
            'return_path' => '', 'region' => 'us', 'transactional' => true,
            'open_tracking' => false, 'click_tracking' => false,
        ], $settings);

        $mailer = new SparkPostMailer\Mailer();
        $configure($mailer);

        $method = new ReflectionMethod($mailer, 'build_transmission');
        $method->setAccessible(true);

        return $method->invoke($mailer);
    }

    $fixtures = [];

    // 1. the simplest possible send
    $fixtures['plain-text'] = build(function ($m) {
        $m->From = 'webmaster@example.com';
        $m->FromName = 'Webmaster';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/plain';
        $m->Body = 'Plain body.';
        $m->to = [['alice@example.com', 'Alice']];
    });

    // 2. html with a plain-text alternative
    $fixtures['html-with-alt'] = build(function ($m) {
        $m->From = 'webmaster@example.com';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/html';
        $m->Body = '<p>HTML body.</p>';
        $m->AltBody = 'Plain alternative.';
        $m->to = [['alice@example.com', 'Alice']];
    });

    // 3. the one that matters: to + cc + bcc, and how each is addressed
    $fixtures['to-cc-bcc'] = build(function ($m) {
        $m->From = 'webmaster@example.com';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/plain';
        $m->Body = 'Body.';
        $m->to = [['alice@example.com', 'Alice'], ['amy@example.com', '']];
        $m->setCc([['bob@example.com', 'Bob']]);
        $m->setBcc([['carol@example.com', '']]);
    });

    // 4. reply-to and custom headers, including ones the API rejects
    $fixtures['headers'] = build(function ($m) {
        $m->From = 'webmaster@example.com';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/plain';
        $m->Body = 'Body.';
        $m->to = [['alice@example.com', 'Alice']];
        $m->ReplyTo = [['reply@example.com', 'Reply Desk'], ['second@example.com', '']];
        $m->setHeaders([
            ['X-Campaign', 'spring'],
            ['Subject', 'should be dropped'],
            ['Message-ID', 'should be dropped'],
            ['X-Empty', ''],
        ]);
    });

    // 5. an attachment and an inline image
    $fixtures['attachments'] = build(function ($m) {
        $m->From = 'webmaster@example.com';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/html';
        $m->Body = '<p>See <img src="cid:0"></p>';
        $m->to = [['alice@example.com', 'Alice']];
        //          [source, filename, name, encoding, type, is_string, disposition, cid]
        $m->setAttachments([
            ['INVOICE-BYTES', '', 'invoice.pdf', 'base64', 'application/pdf', true, 'attachment', ''],
            ['LOGO-BYTES', '', 'logo.png', 'base64', 'image/png', true, 'inline', '0'],
        ]);
    });

    // 6. the sandbox domain, and a return path
    $fixtures['sandbox-and-return-path'] = build(function ($m) {
        $m->From = 'test@SparkPostBox.com';
        $m->Subject = 'Hello';
        $m->ContentType = 'text/plain';
        $m->Body = 'Body.';
        $m->to = [['alice@example.com', 'Alice']];
    }, ['return_path' => 'bounces@example.com', 'open_tracking' => true, 'click_tracking' => true, 'transactional' => false]);

    foreach ($fixtures as $name => $payload) {
        file_put_contents(
            __DIR__ . '/' . $name . '.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    echo 'recaptured ' . count($fixtures) . " fixtures from {$plugin}\n";

}
