<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Appliance mode exists so aipanel can be installed on a server that already
 * runs another control panel. That promise is only worth anything if the agent
 * genuinely never touches the host — so this asserts it, rather than trusting
 * that every future edit remembers.
 */
final class ApplianceModeTest extends TestCase
{
    private function probe(): string
    {
        $fixture = __DIR__ . '/fixtures/appliance-probe.php';
        self::assertFileExists($fixture);

        $output = shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1');

        return (string) $output;
    }

    public function testApplianceModeRunsNoHostMutatingCommand(): void
    {
        $output = $this->probe();

        self::assertStringContainsString(
            'host-mutating commands or host paths: NONE',
            $output,
            "Appliance mode emitted a host command or host path:\n" . $output
        );
    }

    public function testApplianceModeProvisionsThroughTheContainerRuntimeOnly(): void
    {
        $output = $this->probe();

        // Everything it does should be a runtime call.
        foreach (['docker network inspect', 'docker run', 'docker exec aipanel-edge-1 nginx -t'] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
    }

    /** The edge is validated before it is reloaded: a bad config would take every tenant down. */
    public function testEdgeConfigIsTestedBeforeReload(): void
    {
        $output = $this->probe();

        $test = strpos($output, 'nginx -t');
        $reload = strpos($output, 'nginx -s');

        self::assertNotFalse($test, 'the edge config is never validated');
        self::assertNotFalse($reload, 'the edge is never reloaded');
        self::assertLessThan($reload, $test, 'the edge was reloaded before its config was validated');
    }
}
