<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use AIPanel\Deploy\DeployService;
use PHPUnit\Framework\TestCase;

/**
 * A website is two environments on one repository:
 *
 *   sandbox.example.com  ← the 'sandbox' branch
 *   example.com          ← the 'main' branch
 *
 * The separation rests entirely on which push reaches which environment, so
 * that rule is tested directly: a sandbox push must never touch production.
 */
final class SandboxEnvironmentTest extends TestCase
{
    /** @return array<string,mixed> */
    private function site(string $environment, string $branch): array
    {
        return [
            'id' => $environment === 'sandbox' ? 2 : 1,
            'environment' => $environment,
            'deploy_branch' => $branch,
            'domain' => $environment === 'sandbox' ? 'sandbox.example.com' : 'example.com',
        ];
    }

    public function testPushToMainDeploysProductionOnly(): void
    {
        $production = $this->site('production', 'main');
        $sandbox = $this->site('sandbox', 'sandbox');

        self::assertTrue(DeployService::deploysRef($production, 'refs/heads/main'));
        self::assertFalse(DeployService::deploysRef($sandbox, 'refs/heads/main'));
    }

    public function testPushToSandboxDeploysSandboxOnly(): void
    {
        $production = $this->site('production', 'main');
        $sandbox = $this->site('sandbox', 'sandbox');

        self::assertTrue(DeployService::deploysRef($sandbox, 'refs/heads/sandbox'));
        self::assertFalse(
            DeployService::deploysRef($production, 'refs/heads/sandbox'),
            'A sandbox push must never reach production.'
        );
    }

    public function testOtherBranchesDeployNothing(): void
    {
        foreach (['production' => 'main', 'sandbox' => 'sandbox'] as $environment => $branch) {
            $site = $this->site($environment, $branch);

            foreach (['refs/heads/feature/x', 'refs/heads/dev', 'refs/heads/release'] as $ref) {
                self::assertFalse(DeployService::deploysRef($site, $ref), "{$ref} must not deploy {$environment}");
            }
        }
    }

    /** A branch whose name merely starts with the deploy branch is a different branch. */
    public function testPrefixMatchingBranchesDoNotDeploy(): void
    {
        $production = $this->site('production', 'main');
        $sandbox = $this->site('sandbox', 'sandbox');

        self::assertFalse(DeployService::deploysRef($production, 'refs/heads/main-backup'));
        self::assertFalse(DeployService::deploysRef($sandbox, 'refs/heads/sandbox-old'));
        self::assertFalse(DeployService::deploysRef($sandbox, 'refs/heads/sandbox/experiment'));
    }

    public function testTagsAndNonBranchRefsDeployNothing(): void
    {
        $production = $this->site('production', 'main');

        self::assertFalse(DeployService::deploysRef($production, 'refs/tags/v1.0.0'));
        self::assertFalse(DeployService::deploysRef($production, 'refs/pull/7/head'));
        self::assertFalse(DeployService::deploysRef($production, 'main'));
        self::assertFalse(DeployService::deploysRef($production, ''));
    }

    public function testAnEnvironmentWithNoBranchDeploysNothing(): void
    {
        self::assertFalse(DeployService::deploysRef(['deploy_branch' => ''], 'refs/heads/main'));
        self::assertFalse(DeployService::deploysRef([], 'refs/heads/main'));
    }

    /** Custom branch names must work the same way — nothing is hardcoded. */
    public function testCustomBranchNamesAreHonoured(): void
    {
        $production = $this->site('production', 'trunk');
        $sandbox = $this->site('sandbox', 'staging');

        self::assertTrue(DeployService::deploysRef($production, 'refs/heads/trunk'));
        self::assertTrue(DeployService::deploysRef($sandbox, 'refs/heads/staging'));
        self::assertFalse(DeployService::deploysRef($production, 'refs/heads/main'));
        self::assertFalse(DeployService::deploysRef($sandbox, 'refs/heads/trunk'));
    }
}
