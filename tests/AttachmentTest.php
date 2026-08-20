<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Exception\InvalidArgumentException;
use Hampel\SparkPost\Transmission\Attachment;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function test_it_base64_encodes_on_the_way_out(): void
    {
        $attachment = Attachment::fromData('note.txt', 'text/plain', 'hello');

        $this->assertSame(['name' => 'note.txt', 'type' => 'text/plain', 'data' => 'aGVsbG8='], $attachment->toArray());
        // and holds the raw bytes, so nothing has to track which side of the encoding it has
        $this->assertSame('hello', $attachment->data);
    }

    public function test_an_inline_image_is_marked_as_one(): void
    {
        $this->assertTrue(Attachment::inline('logo', 'image/png', 'bytes')->inline);
        $this->assertFalse(Attachment::fromData('a.txt', 'text/plain', 'bytes')->inline);
    }

    public function test_it_reads_a_file_from_disk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sparkpost');
        $this->assertNotFalse($path);
        file_put_contents($path, 'on disk');

        try {
            $attachment = Attachment::fromPath($path, 'renamed.txt', 'text/plain');

            $this->assertSame('renamed.txt', $attachment->name);
            $this->assertSame('on disk', $attachment->data);
        } finally {
            unlink($path);
        }
    }

    public function test_it_names_a_file_after_its_basename_by_default(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sparkpost');
        $this->assertNotFalse($path);

        try {
            $this->assertSame(basename($path), Attachment::fromPath($path)->name);
        } finally {
            unlink($path);
        }
    }

    public function test_an_unreadable_file_is_a_caller_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Attachment::fromPath('/nonexistent/nothing-here.pdf');
    }
}
