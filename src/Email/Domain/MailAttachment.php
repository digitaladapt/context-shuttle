<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * One attachment's *metadata* — never its content.
 *
 * Finding 13: `attachmentCount()`/`hasAttachments()` use the MIME parse path
 * and report `0` for a message whose body was never fetched, while
 * `BODYSTRUCTURE` gives the same detail cheaply (0.005 s for a 3.4 MB
 * message). The design's rule is therefore: metadata always, content never.
 *
 * `size` is nullable because not every server reports it in `BODYSTRUCTURE`,
 * and inventing a zero would be a lie about an empty file.
 */
final readonly class MailAttachment
{
    public function __construct(
        public string $name,
        public string $contentType,
        public ?int $size,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'content_type' => $this->contentType,
            'size' => $this->size,
        ];
    }
}
