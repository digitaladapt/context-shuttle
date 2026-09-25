<?php

declare(strict_types=1);

namespace App\Email\Imap;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Mailbox;
use RuntimeException;
use Throwable;

/**
 * Owns the IMAP connection's lifecycle for one tool invocation.
 *
 * Following the design's thread-safety rule: a `Mailbox` is created per
 * invocation and **never** registered as a shared service. The library holds
 * no per-connection statics — verified: the only statics are an effectively
 * immutable MIME parser facade and its DI container — but the connection
 * object itself is stateful, and handing one instance to every request in a
 * FrankenPHP worker is how a stateless gateway grows a shared socket.
 *
 * The connection is opened lazily on first use, so a request that only reads
 * configuration (or is refused by the folder gate) never touches the
 * network, and closed explicitly afterwards. The `finally` in
 * {@see self::with()} is the guarantee: a tool that throws still logs out,
 * rather than leaving a socket for the worker to leak.
 *
 * This is deliberately the *only* class that news up a `Mailbox`; everything
 * else takes a connection object, so there is exactly one place to change if
 * the connection strategy ever does.
 */
final class ImapConnectionFactory
{
    private ?Mailbox $mailbox = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly float $timeout = 15.0,
        private readonly ?ConnectionInterface $connection = null,
    ) {
    }

    /**
     * The port to dial.
     *
     * An unset `IMAP_PORT` arrives as `0` (an empty env string cast to int),
     * and 0 is not a port — so the default is derived from the encryption
     * mode: 993 for implicit TLS, 143 for everything else. Two settings that
     * must agree is a bug waiting to happen, so the caller only has to get
     * the encryption right.
     */
    private function port(): int
    {
        if ($this->port > 0) {
            return $this->port;
        }

        return 'ssl' === strtolower(trim($this->encryption)) ? 993 : 143;
    }

    /**
     * Whether the deployment has enough configuration to attempt a
     * connection at all.
     *
     * An unconfigured deployment must fail with a sentence naming the env
     * var, not a container `TypeError` — the same contract every other tool
     * here honours.
     */
    public function isConfigured(): bool
    {
        if (null !== $this->connection) {
            return true;
        }

        return '' !== trim($this->host)
            && '' !== trim($this->username)
            && '' !== trim($this->password);
    }

    /**
     * The shared-per-invocation mailbox, connected on first use.
     *
     * @throws RuntimeException when the deployment is not configured
     */
    public function mailbox(): Mailbox
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Email is not configured. Set IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD in .env.local (IMAP_PASSWORD may also live in the secrets vault: bin/console secrets:set IMAP_PASSWORD).');
        }

        if (null === $this->mailbox) {
            $this->mailbox = Mailbox::make([
                'host' => trim($this->host),
                'port' => $this->port(),
                'username' => $this->username,
                'password' => $this->password,
                'encryption' => $this->encryptionValue(),
                // A self-signed certificate on a private server would
                // otherwise turn every call into a transport failure; the
                // operator chose the host, so the certificate is their
                // business, exactly as with the other tools here.
                'validate_cert' => true,
                'timeout' => (int) $this->timeout,
                'authentication' => 'plain',
            ]);

            // An injected connection is the library's own seam —
            // `Mailbox::connect(?ConnectionInterface)` documents it — and it
            // is what lets the test suite drive the *real* client against a
            // scripted server rather than mocking the client out. That
            // matters here: every defect this class works around (finding
            // 3's encoding, the partial-fetch token shape) is a parsing
            // behaviour that a mock would paper over.
            $this->mailbox->connect($this->connection);
        }

        return $this->mailbox;
    }

    /**
     * The encryption mode the library understands.
     *
     * An unset `IMAP_ENCRYPTION` arrives as an empty string, which the
     * library would pass through as a `tcp` transport — fine for the test
     * server here, wrong for a real one, where silence should mean TLS. `ssl`
     * is the safe default because it is what port 993 servers expect.
     */
    private function encryptionValue(): string
    {
        $encryption = strtolower(trim($this->encryption));

        return \in_array($encryption, ['ssl', 'tls', 'starttls', 'tcp'], true) ? $encryption : 'ssl';
    }

    /**
     * Run a callback against a connection and always hang up afterwards.
     *
     * @template T
     *
     * @param callable(Mailbox): T $callback
     *
     * @return T
     */
    public function with(callable $callback): mixed
    {
        $mailbox = $this->mailbox();

        try {
            return $callback($mailbox);
        } finally {
            $this->close();
        }
    }

    /**
     * Log out and drop the connection, swallowing transport noise.
     *
     * A failure to say goodbye is not a reason to fail a request that
     * otherwise succeeded: the socket is going away either way.
     */
    public function close(): void
    {
        if (null === $this->mailbox) {
            return;
        }

        $mailbox = $this->mailbox;
        $this->mailbox = null;

        try {
            $mailbox->disconnect();
        } catch (Throwable) {
            // Nothing useful to do — the connection is being abandoned.
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
