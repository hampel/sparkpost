<?php

/**
 * Exercise: render template-language cases through SparkPost's content previewer - calls the API, sends nothing.
 *
 * Nothing here creates a message or an event. POST utils/content-previewer renders inline
 * content through the same template engine a transmission uses and returns the result, so
 * it can answer which constructs fail to render without spending a send, a sandbox message
 * or a sink delivery.
 *
 * The questions, as they stood on 2026-11-01. A consumer had 17 transmissions with inline
 * content and NO substitution_data fail at generation between 2026-10-19 and 2026-10-22,
 * with reasons like
 *
 *   554 5.3.3 [internal] Error while rendering part html: ... line 102: substitution
 *   value 'contact' did not exist or was null
 *
 * The suspicion is that user-written text containing {{...}} reached the template engine.
 * But SparkPost documents a missing or null top-level variable as rendering empty, so a
 * plain {{contact}} should not fail. So, with substitution_data empty:
 *
 *   A  {{contact}}                  empty, as documented - or the failure?
 *   B  {{contact.name}}             does a member of a missing value fail?
 *   C  {{each contact}}x{{end}}     does iterating a missing value fail?
 *   D  {{contact[0]}}               does an index into a missing value fail?
 *   E-I  escaped user text          Transmission::escapeTemplateSyntax() on
 *                                   {{contact.name}}, {{{contact.name}}}, {{}, {}} and
 *                                   x}}}. Does each render as exactly the text it was
 *                                   given? The last four need the single and triple
 *                                   macros as well as the double pair, and leave no
 *                                   literal brace beside a macro to join it.
 *
 *   J-M  the same in a header       a custom header carrying world, {{contact}},
 *                                   {{contact.name}} and the escaped {{contact.name}},
 *                                   with plain bodies. Does SparkPost template headers
 *                                   at all? J shows whether the previewer even returns
 *                                   headers - if it does not, K-M can only answer by
 *                                   failing, and a render says nothing either way.
 *
 * E-I are built by the package's own helper, so a run checks it against the real engine;
 * each prints the line it should render beside the line it did. Answered on 2026-11-01 and
 * 2026-11-02: A and C render empty, B and D fail with the consumer's error, and E-I all
 * render exactly the text given. The README carries the result.
 *
 * A control with no template syntax runs first. If it fails, the endpoint itself refused
 * the request - a field it requires, a key without the grant - and nothing after it would
 * say anything about the template language, so the run stops there.
 *
 * What this does NOT prove: that the previewer and generation agree. A construct that
 * renders here could still fail when a real transmission is generated. Settling that needs
 * a send - to sink recipients, not the sandbox domain, whose allowance is small and
 * lifetime - and that is a separate decision from running this.
 *
 * Needs SPARKPOST_API_KEY. Uses SPARKPOST_FROM when it is set.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use Hampel\SparkPost\Exception\ApiException;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transmission\Transmission;

$io->title('sparkpost · template language through the content previewer');

$key = getenv('SPARKPOST_API_KEY');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

$io->value('mode', 'preview only - nothing is sent, no message or event is created');

$sparkpost = SparkPost::withKey($key, new Client(), getenv('SPARKPOST_REGION') ?: null);
$from = getenv('SPARKPOST_FROM') ?: 'preview@example.com';

// Case => [what is shown, what is sent, what it should render or null, where it goes]. A
// null means the question is open; an escaped case should render exactly the text it was
// given. A header case puts the construct in PROBE_HEADER and leaves the bodies plain.
const PROBE_HEADER = 'X-Template-Probe';

$cases = [
    'control' => ['world', 'world', null, 'body'],
    'A' => ['{{contact}}', '{{contact}}', null, 'body'],
    'B' => ['{{contact.name}}', '{{contact.name}}', null, 'body'],
    'C' => ['{{each contact}}x{{end}}', '{{each contact}}x{{end}}', null, 'body'],
    'D' => ['{{contact[0]}}', '{{contact[0]}}', null, 'body'],
];

$escaped = [
    'E' => '{{contact.name}}',
    'F' => '{{{contact.name}}}',
    'G' => '{{}',
    'H' => '{}}',
    'I' => 'x}}}',
];

foreach ($escaped as $case => $text) {
    $cases[$case] = ['escaped ' . $text, Transmission::escapeTemplateSyntax($text), $text, 'body'];
}

$cases['J'] = ['header: world', 'world', 'world', 'header'];
$cases['K'] = ['header: {{contact}}', '{{contact}}', null, 'header'];
$cases['L'] = ['header: {{contact.name}}', '{{contact.name}}', null, 'header'];
$cases['M'] = [
    'header: escaped {{contact.name}}',
    Transmission::escapeTemplateSyntax('{{contact.name}}'),
    '{{contact.name}}',
    'header',
];

// The middle line of a rendered part. Io quotes a string value, so an empty substitution
// shows as the doubled space it leaves - 'Hello  there' - rather than vanishing.
$middle = static function (mixed $part): string {
    if (!is_string($part)) {
        return '(not returned)';
    }

    return explode("\n", $part)[1] ?? $part;
};

$outcomes = [];

foreach ($cases as $case => [$shown, $construct, $literal, $where]) {
    $io->line();
    $io->info(sprintf('%s   %s', $case, $shown));

    // The failing transmissions had no substitution_data, and the question is what the
    // engine does with a construct that refers to nothing. The previewer will not take the
    // field absent - 422, code 1400, 'substitution_data' is required (2026-11-01) - so it
    // gets an empty object, the nearest it accepts. An object, not [], which would encode
    // as a JSON array.
    $inBody = $where === 'body' ? $construct : 'world';

    $payload = [
        'substitution_data' => new stdClass(),
        'content' => [
            'from' => $from,
            'subject' => 'template repro ' . $case,
            'text' => "line one\nHello {$inBody} there\nline three",
            'html' => "<p>line one</p>\n<p>Hello {$inBody} there</p>\n<p>line three</p>",
        ],
    ];

    if ($where === 'header') {
        $payload['content']['headers'] = [PROBE_HEADER => "Hello {$construct} there"];
    }

    try {
        $body = $sparkpost->connection()->post('utils/content-previewer', $payload);
    } catch (ApiException $e) {
        // The status and errors[] are the answer here, and ApiException keeps both whole -
        // only its message is abbreviated. So the package's own error path is lossless
        // for this probe, and there is no need to go round it.
        $io->warn(sprintf('rejected - HTTP %d', $e->statusCode));

        foreach ($e->errors as $error) {
            $io->line(sprintf(
                '  [%s] %s%s',
                is_scalar($error['code'] ?? null) ? $error['code'] : '-',
                is_scalar($error['message'] ?? null) ? $error['message'] : '(no message)',
                is_scalar($error['description'] ?? null) ? ' - ' . $error['description'] : ''
            ));
        }

        if ($e->errors === []) {
            $io->value('body length', (string) strlen($e->body));
        }

        $outcomes[$case] = sprintf('rejected, HTTP %d', $e->statusCode);

        if ($case === 'control') {
            $io->line();
            $io->error('✗ The control failed, so the endpoint refused the request itself.');
            $io->info('Read the errors above for what it wants. Nothing below would mean anything.');

            exit(1);
        }

        continue;
    } catch (ExceptionInterface $e) {
        // Not an answer about the template language - the request never got one.
        $io->error('✗ ' . $e::class);
        $io->value('message', $e->getMessage());

        exit(1);
    }

    $results = is_array($body['results'] ?? null) ? $body['results'] : [];

    $io->success('rendered');

    if ($where === 'header') {
        // Whether the previewer returns headers at all is part of the question, so say what
        // came back rather than assuming the shape.
        $headers = is_array($results['headers'] ?? null) ? $results['headers'] : null;
        $value = $headers[PROBE_HEADER] ?? null;

        $io->values(array_merge(
            $literal === null ? [] : ['should be' => "Hello {$literal} there"],
            [
                'header' => is_string($value) ? $value : '(not returned)',
                'result keys' => implode(', ', array_map('strval', array_keys($results))),
            ]
        ));

        $outcomes[$case] = 'rendered';

        continue;
    }

    $io->values(array_merge(
        $literal === null ? [] : ['should be' => "Hello {$literal} there"],
        [
            'text, line 2' => $middle($results['text'] ?? null),
            'html, line 2' => $middle($results['html'] ?? null),
        ]
    ));

    $outcomes[$case] = 'rendered';
}

$io->line();
$io->values($outcomes);
$io->line();
$io->info('Preview only: this says what the template engine does with each construct, not');
$io->info('that generation agrees. A send to sink recipients settles that, separately.');
