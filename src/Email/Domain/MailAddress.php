<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * A sender or recipient, in the shape the tools emit.
 *
 * `address` is the routable part and `name` is what the sender chose to
 * display — which, like every other string an email carries, is
 * attacker-controlled text. The split is kept because a model reasoning
 * about "who sent this" needs the address to be trustworthy-ish and the
 * display name to be visibly separate from it.
 */
final readonly class MailAddress
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {
    }

    /**
     * Parse one `Name <address>` string, leniently.
     *
     * The library hands back already-parsed `Address` objects for the
     * envelope fields, but `header('From')` is a raw string, so this exists
     * for the paths that read headers directly. A malformed value becomes an
     * address with a null name rather than an exception: a broken `From:`
     * must not fail a listing.
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if ('' === $value) {
            return new self('');
        }

        // "Name <address>" — the overwhelmingly common shape.
        if (1 === preg_match('/^(.*)<([^>]*)>\s*$/s', $value, $matches)) {
            $name = trim($matches[1]);
            $address = trim($matches[2]);

            // Strip surrounding quotes from a quoted display name.
            if (\strlen($name) >= 2 && '"' === $name[0] && str_ends_with($name, '"')) {
                $name = substr($name, 1, -1);
            }

            return new self($address, '' === $name ? null : $name);
        }

        return new self($value);
    }

    /**
     * @return array{address: string, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'name' => $this->name,
        ];
    }
}
