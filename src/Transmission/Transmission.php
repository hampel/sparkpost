<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transmission;

use Hampel\SparkPost\Exception\InvalidArgumentException;

/**
 * Builds a SparkPost transmission payload.
 *
 * This is the piece three separate implementations had each solved on their own before this
 * package existed, and the reason it is worth a type is that most of the difficulty is not
 * in the obvious fields. It is in the handful of rules below, each of which is easy to get
 * wrong and produces mail that looks fine until someone reads the headers.
 *
 *  - SparkPost sends one message per recipient, so without `header_to` every recipient
 *    sees a To: line containing only themselves. Setting it on every recipient is what
 *    reproduces ordinary To/Cc semantics.
 *  - With `header_to` set, a per-recipient `name` makes the delivered To: line render
 *    twice, so the two are mutually exclusive.
 *  - Cc recipients are addressed through the recipients list like anyone else. The `CC`
 *    header is what actually makes them visible. Bcc gets no header, which is what makes
 *    it blind.
 *  - Some headers are derived by SparkPost from the transmission itself and are rejected
 *    if you also pass them in content.headers.
 *
 * Each is asserted in TransmissionTest - individually, and once as a whole payload.
 */
final class Transmission
{
    /**
     * Headers SparkPost either rejects in content.headers or derives itself from the
     * transmission body.
     */
    private const DISALLOWED_HEADERS = [
        'subject',
        'from',
        'to',
        'cc',
        'bcc',
        'reply-to',
        'return-path',
        'content-type',
        'content-transfer-encoding',
        'mime-version',
        'message-id',
        'date',
    ];

    /**
     * SparkPost's sandbox sending domain. Mail from it needs the sandbox option, and
     * silently fails without it.
     */
    private const SANDBOX_DOMAIN = '@sparkpostbox.com';

    private ?Address $from = null;

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    /** @var list<Address> */
    private array $replyTo = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<Attachment> */
    private array $attachments = [];

    private ?bool $transactional = null;

    private ?bool $openTracking = null;

    private ?bool $clickTracking = null;

    private ?bool $sandbox = null;

    /** @var array<string, mixed> */
    private array $extraOptions = [];

    private ?string $returnPath = null;

    private ?string $campaignId = null;

    private ?string $description = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** @var array<string, mixed> */
    private array $substitutionData = [];

    /** @var array<string, mixed>|null */
    private ?array $rawContent = null;

    /** @var list<Address>|null */
    private ?array $deliverTo = null;

    public static function make(): self
    {
        return new self();
    }

    public function from(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);

        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);

        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);

        return $this;
    }

    /**
     * Deliver to these addresses instead of the ones in to()/cc()/bcc(), leaving the
     * To: and CC: headers exactly as those built them.
     *
     * This is the seam an envelope override needs. A mail framework lets a listener
     * rewrite where a message is actually delivered - to a sink address while testing, to
     * a single inbox on a staging site - without touching the headers the recipient sees.
     * SparkPost keeps those two things in separate places, and this is the one.
     *
     * @param  list<Address>  $addresses
     */
    public function deliverTo(array $addresses): self
    {
        $this->deliverTo = $addresses;

        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo[] = new Address($email, $name);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    public function transactional(bool $transactional = true): self
    {
        $this->transactional = $transactional;

        return $this;
    }

    public function openTracking(bool $openTracking = true): self
    {
        $this->openTracking = $openTracking;

        return $this;
    }

    public function clickTracking(bool $clickTracking = true): self
    {
        $this->clickTracking = $clickTracking;

        return $this;
    }

    /**
     * Force the sandbox option on or off. Left alone, it is switched on automatically for
     * mail from SparkPost's sandbox domain, which is the only time it is needed and the
     * only time forgetting it is silent.
     */
    public function sandbox(bool $sandbox = true): self
    {
        $this->sandbox = $sandbox;

        return $this;
    }

    /**
     * Any other transmission option - ip_pool, start_time, click_tracking overrides that
     * this class has not grown a method for.
     */
    public function option(string $key, mixed $value): self
    {
        $this->extraOptions[$key] = $value;

        return $this;
    }

    /**
     * The envelope FROM - where bounces are delivered, and the domain a receiver runs SPF
     * against. Distinct from the header From set by from(), which is what the reader sees
     * and what DMARC aligns against.
     *
     * SparkPost does not validate this when the transmission is posted, and that asymmetry
     * catches people out: a from() on a domain that is not a configured sending domain is
     * rejected outright with a 400, while any return path at all is accepted with a 200.
     * A 200 therefore says the payload was well formed and nothing about this address.
     *
     * What happens to it afterwards was measured on a real account rather than read from
     * the API, so treat it as reported: a domain the account is not configured for is
     * silently discarded and the message goes out under the fallback bounce domain - the
     * account's default, or the subaccount's where the key is a subaccount key, or
     * sparkpostmail.com where neither is set. A wrong value and no value therefore end up
     * in exactly the same place, and what the wrong one costs is the appearance of having
     * configured something.
     *
     * Only the domain survives in any case. SparkPost replaces the local part with an
     * identifier of its own, so 'bounces@example.com' is delivered as '<id>@example.com' -
     * a local part you did not choose is what success looks like here.
     */
    public function returnPath(string $returnPath): self
    {
        $this->returnPath = $returnPath;

        return $this;
    }

    public function campaignId(string $campaignId): self
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $substitutionData
     */
    public function substitutionData(array $substitutionData): self
    {
        $this->substitutionData = $substitutionData;

        return $this;
    }

    /**
     * Send a stored template instead of a body.
     *
     * Templates replace content entirely - subject, from, text and html all come from the
     * stored template, so anything set here is ignored from this point on.
     */
    public function template(string $templateId, bool $useDraft = false): self
    {
        $this->rawContent = ['template_id' => $templateId, 'use_draft_template' => $useDraft];

        return $this;
    }

    /**
     * Send an A/B test, which likewise replaces content entirely.
     */
    public function abTest(string $abTestId): self
    {
        $this->rawContent = ['ab_test_id' => $abTestId];

        return $this;
    }

    /**
     * Set content verbatim, for a shape this class does not model.
     *
     * @param  array<string, mixed>  $content
     */
    public function content(array $content): self
    {
        $this->rawContent = $content;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->deliverTo === null && $this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new InvalidArgumentException('A transmission needs at least one recipient.');
        }

        return self::prune([
            'options' => $this->buildOptions(),
            'recipients' => $this->buildRecipients(),
            'content' => $this->buildContent(),
            'campaign_id' => $this->campaignId,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'substitution_data' => $this->substitutionData,
            'return_path' => $this->returnPath,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(): array
    {
        $options = self::prune([
            'open_tracking' => $this->openTracking,
            'click_tracking' => $this->clickTracking,
            'transactional' => $this->transactional,
        ]);

        if ($this->sandbox ?? $this->isSandboxSender()) {
            $options['sandbox'] = true;
        }

        return $options + $this->extraOptions;
    }

    private function isSandboxSender(): bool
    {
        return $this->from !== null && str_ends_with(strtolower($this->from->email), self::SANDBOX_DOMAIN);
    }

    /**
     * @return list<array{address: array<string, string>}>
     */
    private function buildRecipients(): array
    {
        // Every recipient - To, Cc and Bcc alike - carries the same To: line, which is
        // what makes the delivered message look like ordinary mail.
        $headerTo = Address::formatList($this->to);

        $recipients = [];

        foreach ($this->deliverTo ?? [...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $recipients[] = ['address' => $headerTo === ''
                ? $address->toArray()
                : ['email' => $address->email, 'header_to' => $headerTo]];
        }

        return $recipients;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContent(): array
    {
        if ($this->rawContent !== null) {
            return $this->rawContent;
        }

        return self::prune([
            'from' => $this->from?->toArray(),
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'reply_to' => Address::formatList($this->replyTo),
            'headers' => $this->buildHeaders(),
            'attachments' => $this->filesOfKind(false),
            'inline_images' => $this->filesOfKind(true),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            if ($value === '' || in_array(strtolower($name), self::DISALLOWED_HEADERS, true)) {
                continue;
            }

            $headers[$name] = $value;
        }

        // Cc recipients are addressed through the recipients list; this header is what
        // makes them visible in the delivered message. Bcc deliberately gets none.
        $cc = Address::formatList($this->cc);

        if ($cc !== '') {
            $headers['CC'] = $cc;
        }

        return $headers;
    }

    /**
     * @return list<array{name: string, type: string, data: string}>
     */
    private function filesOfKind(bool $inline): array
    {
        $files = [];

        foreach ($this->attachments as $attachment) {
            if ($attachment->inline === $inline) {
                $files[] = $attachment->toArray();
            }
        }

        return $files;
    }

    /**
     * Drop what was never set, and nothing else.
     *
     * Deliberately not array_filter() with its default callback: that also drops false
     * and 0, which would silently turn `open_tracking => false` into "use the account
     * default" - the exact bug this replaces.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function prune(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );
    }
}
