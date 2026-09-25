<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Where `move_email` may send a message — the destination classes the design
 * names, resolved against configuration.
 *
 * The three classes are deliberately different *kinds* of thing:
 *
 * - **`trash`** and **`archive`** are configured by name (`IMAP_TRASH_FOLDER`
 *   / `IMAP_ARCHIVE_FOLDER`), and unset means the destination does not exist.
 *   `trash` is the "delete" — an operator points it at a real Trash mailbox
 *   or a quarantine folder, and either way a human can recover the message.
 *   There is no tool that expunges, so the guarantee is structural.
 * - **`folder`** is the escape hatch for anything the enum does not name, and
 *   it is the most tightly gated operation in the system: the source must be
 *   in `IMAP_MOVE_SOURCE_FOLDERS` *and* the target in
 *   `IMAP_MOVE_TARGET_FOLDERS`.
 *
 * `IMAP_DELETE_FOLDER` is an alias for `IMAP_TRASH_FOLDER`, spelled the word
 * an operator looks for when they want "where do deleted emails go". It wins
 * when both are set. It is not a separate concept, which is why it resolves
 * into the same slot rather than becoming a fourth destination.
 */
final readonly class MoveDestination
{
    public const TRASH = 'trash';

    public const ARCHIVE = 'archive';

    public const FOLDER = 'folder';

    public const ASTRAY = [self::TRASH, self::ARCHIVE, self::FOLDER];

    public function __construct(
        private string $trashFolder = '',
        private string $archiveFolder = '',
        private string $deleteFolder = '',
    ) {
    }

    /**
     * The folder a named destination resolves to, or null when it is not
     * configured.
     *
     * An unset destination is a *refusal*, not a fallback: a deployment that
     * has not said where trash is does not have a trash.
     */
    public function forName(string $destination, ?string $targetFolder = null): ?string
    {
        return match ($destination) {
            self::TRASH => $this->resolveTrash(),
            self::ARCHIVE => $this->trimmed($this->archiveFolder),
            self::FOLDER => $this->trimmed($targetFolder),
            default => null,
        };
    }

    /**
     * Whether a named destination is configured at all.
     *
     * Used by `list_email_folders` to report which destinations exist, so a
     * caller can see that `trash` is unavailable rather than discovering it
     * by being refused.
     */
    public function isConfigured(string $destination): bool
    {
        return null !== $this->forName($destination);
    }

    /**
     * Which destinations are configured, for folder listings.
     *
     * @return list<string>
     */
    public function configuredNames(): array
    {
        return array_values(array_filter(
            [self::TRASH, self::ARCHIVE],
            fn (string $name): bool => $this->isConfigured($name),
        ));
    }

    /**
     * The configured trash folder, with `IMAP_DELETE_FOLDER` taking
     * precedence.
     *
     * The alias wins because an operator who set both was more specific: they
     * went looking for the word "delete" after already having set "trash".
     */
    private function resolveTrash(): ?string
    {
        return $this->trimmed($this->deleteFolder) ?? $this->trimmed($this->trashFolder);
    }

    private function trimmed(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return null === $value || '' === $value ? null : $value;
    }
}
