<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use AIPanel\AI\Sandbox;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox bounds what the AI author can touch. These tests cover the
 * escapes: traversal, symlinks out of the tree, and the deny list.
 */
final class SandboxTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aipanel-sbx-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/app', 0o755, true);
        mkdir($this->root . '/vendor', 0o755, true);
        mkdir($this->root . '/.git', 0o755, true);

        file_put_contents($this->root . '/index.php', "<?php echo 'hello';\n");
        file_put_contents($this->root . '/app/Service.php', "<?php class Service {}\n");
        file_put_contents($this->root . '/vendor/lib.php', "<?php // dependency\n");
        file_put_contents($this->root . '/.git/config', "[core]\n");
        file_put_contents($this->root . '/.env', "SECRET=abc\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testReadsAFileInTheRepository(): void
    {
        $sandbox = new Sandbox($this->root);
        self::assertStringContainsString('hello', $sandbox->readFile('index.php'));
    }

    public function testWritesAndReportsTheChange(): void
    {
        $sandbox = new Sandbox($this->root);
        $sandbox->writeFile('app/New.php', "<?php class NewThing {}\n");

        self::assertFileExists($this->root . '/app/New.php');
        self::assertContains('app/New.php', $sandbox->changedFiles());
    }

    public function testRefusesPathTraversal(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectException(\RuntimeException::class);
        $sandbox->readFile('../../etc/passwd');
    }

    public function testRefusesAbsolutePaths(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectExceptionMessage('Paths must be relative to the repository root.');
        $sandbox->readFile('/etc/passwd');
    }

    public function testRefusesToFollowASymlinkOutOfTheTree(): void
    {
        symlink('/etc', $this->root . '/escape');
        $sandbox = new Sandbox($this->root);

        $this->expectExceptionMessage('outside the repository');
        $sandbox->readFile('escape/hostname');
    }

    /**
     * Regression: trimming the '.' character (rather than a leading "./")
     * turned ".git/config" into "git/config" and slipped past the deny list.
     */
    public function testDenyListCoversDotDirectories(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectExceptionMessage('not available to the agent');
        $sandbox->readFile('.git/config');
    }

    public function testDependenciesAreOffLimits(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectExceptionMessage('not available to the agent');
        $sandbox->readFile('vendor/lib.php');
    }

    public function testLeadingDotSlashIsAccepted(): void
    {
        $sandbox = new Sandbox($this->root);
        self::assertStringContainsString('hello', $sandbox->readFile('./index.php'));
    }

    public function testWriteOutsideTheTreeIsRefused(): void
    {
        $sandbox = new Sandbox($this->root);

        $this->expectException(\RuntimeException::class);
        $sandbox->writeFile('../escaped.php', '<?php');
    }

    public function testChecksFallBackToLintingWhenTheRepoHasNoSuite(): void
    {
        $sandbox = new Sandbox($this->root);
        $sandbox->writeFile('app/Broken.php', "<?php class Broken { \n");

        $result = $sandbox->runChecks();
        self::assertFalse($result['passed'], 'A file that does not parse must fail the checks.');
    }
}
