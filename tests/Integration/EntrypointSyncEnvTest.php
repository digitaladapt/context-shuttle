<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use PHPUnit\Framework\TestCase;

/**
 * Runs docker/entrypoint.sh's sync-env validation against a matrix of
 * TASKCHAMPION_* combinations, in a sandbox with `task` on PATH.
 *
 * Pins the two ways the check shipped broken (PR #43, post-merge):
 * string-matching a space-joined list with a trailing space never matched
 * the "all three set" case, and `set -e` killed the script silently when
 * the *last* variable in the loop was empty. A validation check that
 * rejects valid config — or dies without a message — is worse than no
 * check at all.
 *
 * @internal
 *
 * @covers \App\Tests\Integration\EntrypointSyncEnvTest
 */
final class EntrypointSyncEnvTest extends TestCase
{
    private string $tmpDir = '';

    #[Override]
    protected function setUp(): void
    {
        $task = trim((string) shell_exec('command -v task 2>/dev/null'));
        if ('' === $task) {
            $this->markTestSkipped('task binary not available');
        }
        $this->tmpDir = sys_get_temp_dir().'/entrypoint-sync-test-'.uniqid();
        mkdir($this->tmpDir.'/data', 0777, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }
    }

    /**
     * @param array<string, string> $env extra env vars for the entrypoint
     *
     * @return array{exit: int, stderr: string, taskrc: string}
     */
    private function runEntrypoint(array $env): array
    {
        $script = \dirname(__DIR__, 2).'/docker/entrypoint.sh';
        $envArgs = '';
        foreach ($env as $k => $v) {
            $envArgs .= ' '.escapeshellarg($k.'='.$v);
        }
        $cmd = \sprintf(
            'env -i PATH=%s HOME=%s TASKDATA=%s TASKRC=%s%s sh %s true',
            escapeshellarg(getenv('PATH')),
            escapeshellarg($this->tmpDir),
            escapeshellarg($this->tmpDir.'/data'),
            escapeshellarg($this->tmpDir.'/data/taskrc'),
            $envArgs,
            escapeshellarg($script),
        );
        exec($cmd.' 2>&1', $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $taskrc = '';
        if (is_file($this->tmpDir.'/data/taskrc')) {
            $taskrc = (string) file_get_contents($this->tmpDir.'/data/taskrc');
        }

        return ['exit' => $exitCode, 'stderr' => $output, 'taskrc' => $taskrc];
    }

    public function test_all_three_set_is_accepted_and_config_written(): void
    {
        $r = $this->runEntrypoint([
            'TASKCHAMPION_SYNC_URL' => 'https://sync.example.com',
            'TASKCHAMPION_SYNC_CLIENT_ID' => 'some-client-id',
            'TASKCHAMPION_SYNC_ENCRYPTION_SECRET' => 'some-secret',
        ]);

        self::assertSame(0, $r['exit'], "all three set must boot, got:\n".$r['stderr']);
        self::assertStringContainsString('sync.server.url=https://sync.example.com', $r['taskrc']);
        self::assertStringContainsString('sync.server.client_id=some-client-id', $r['taskrc']);
        self::assertStringContainsString('sync.encryption_secret=some-secret', $r['taskrc']);
    }

    public function test_none_set_is_accepted(): void
    {
        $r = $this->runEntrypoint([]);
        self::assertSame(0, $r['exit'], "no sync vars set must boot, got:\n".$r['stderr']);
        self::assertStringNotContainsString('sync.server.url', $r['taskrc']);
    }

    public function test_only_url_set_is_rejected(): void
    {
        $r = $this->runEntrypoint(['TASKCHAMPION_SYNC_URL' => 'https://sync.example.com']);
        self::assertSame(1, $r['exit'], 'partial config must be rejected');
        self::assertStringContainsString('partially configured', $r['stderr']);
        // Must name the missing variables, not just complain.
        self::assertStringContainsString('TASKCHAMPION_SYNC_CLIENT_ID', $r['stderr']);
        self::assertStringContainsString('TASKCHAMPION_SYNC_ENCRYPTION_SECRET', $r['stderr']);
    }

    public function test_url_and_secret_but_no_client_id_is_rejected(): void
    {
        $r = $this->runEntrypoint([
            'TASKCHAMPION_SYNC_URL' => 'https://sync.example.com',
            'TASKCHAMPION_SYNC_ENCRYPTION_SECRET' => 'some-secret',
        ]);
        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('TASKCHAMPION_SYNC_CLIENT_ID', $r['stderr']);
    }

    /**
     * The empty-LAST-variable case: `set -e` + a trailing `&&` list in a
     * command substitution made the script die with no message at all
     * when the last loop variable was the empty one.
     */
    public function test_only_encryption_secret_set_is_rejected_loudly(): void
    {
        $r = $this->runEntrypoint(['TASKCHAMPION_SYNC_ENCRYPTION_SECRET' => 'some-secret']);
        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('partially configured', $r['stderr']);
    }

    public function test_empty_string_values_count_as_unset(): void
    {
        // docker-compose interpolates missing vars as empty strings; the
        // entrypoint must treat those as "not set", not as configured.
        $r = $this->runEntrypoint([
            'TASKCHAMPION_SYNC_URL' => 'https://sync.example.com',
            'TASKCHAMPION_SYNC_CLIENT_ID' => '',
            'TASKCHAMPION_SYNC_ENCRYPTION_SECRET' => '',
        ]);
        self::assertSame(1, $r['exit'], 'empty-string siblings count as unset');
        self::assertStringContainsString('partially configured', $r['stderr']);
    }
}
